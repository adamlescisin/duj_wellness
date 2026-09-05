<?php

declare(strict_types=1);

namespace Duj\Wellness;

use Duj\Wellness\Accommodation\AccommodationClassifier;
use Duj\Wellness\Accommodation\AccommodationSyncService;
use Duj\Wellness\Accommodation\IcsParser;
use Duj\Wellness\Cron\ExpireHoldsJob;
use Duj\Wellness\Cron\SyncAccommodationJob;
use Duj\Wellness\Domain\AccessCodeService;
use Duj\Wellness\Domain\AvailabilityService;
use Duj\Wellness\Domain\BookingService;
use Duj\Wellness\Domain\CutoffPolicy;
use Duj\Wellness\Domain\PricingService;
use Duj\Wellness\Domain\ScheduleResolver;
use Duj\Wellness\Domain\TierResolver;
use Duj\Wellness\Migrations\Migration001Initial;
use Duj\Wellness\Migrations\MigrationRunner;
use Duj\Wellness\Repository\AccessCodeRepository;
use Duj\Wellness\Repository\AccommodationRepository;
use Duj\Wellness\Repository\BookingItemRepository;
use Duj\Wellness\Repository\BookingRepository;
use Duj\Wellness\Repository\BookingRepositoryInterface;
use Duj\Wellness\Repository\DayLockRepository;
use Duj\Wellness\Repository\DayLockRepositoryInterface;
use Duj\Wellness\Repository\PriceRepository;
use Duj\Wellness\Repository\ResourceRepository;
use Duj\Wellness\Repository\ScheduleRepository;
use Duj\Wellness\Notification\ActionTokenService;
use Duj\Wellness\Notification\Channels\EmailChannel;
use Duj\Wellness\Notification\Channels\TelegramChannel;
use Duj\Wellness\Notification\IcsGenerator;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Notification\TemplateRenderer;
use Duj\Wellness\Payment\StripeGatewayFactory;
use Duj\Wellness\Payment\StripeWebhookHandler;
use Duj\Wellness\Rest\AccessCodeController;
use Duj\Wellness\Rest\ActionController;
use Duj\Wellness\Rest\AvailabilityController;
use Duj\Wellness\Rest\BookingsController;
use Duj\Wellness\Rest\WebhooksController;
use Duj\Wellness\Support\RateLimiter;
use Duj\Wellness\Support\Settings;

/**
 * Centrální bootstrap třídy. Registruje hooky a spravuje lifecycle.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        $this->loadTextdomain();
        $this->runMigrations();
        $this->registerHooks();
    }

    private function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'duj-wellness',
            false,
            dirname(DUJ_WELLNESS_BASENAME) . '/languages'
        );
    }

    private function runMigrations(): void
    {
        $runner = new MigrationRunner();
        $runner->register(new Migration001Initial());
        $runner->run();
    }

    private function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('init', [$this, 'registerCronSchedules']);
        add_action(ExpireHoldsJob::HOOK, [$this, 'runExpireHolds']);
        add_action(SyncAccommodationJob::HOOK, [$this, 'runSyncAccommodation']);
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            __('Wellness rezervace', 'duj-wellness'),
            __('Wellness', 'duj-wellness'),
            'duj_manage_bookings',
            'duj-wellness',
            static function (): void {
                echo '<div class="wrap"><h1>'
                    . esc_html__('Wellness rezervace — Domeček u Josefa', 'duj-wellness')
                    . '</h1><p>'
                    . esc_html__('Plugin je aktivní. Rezervační systém bude dostupný po dokončení implementace.', 'duj-wellness')
                    . '</p></div>';
            },
            'dashicons-heart',
            30
        );
    }

    public function registerCronSchedules(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules['duj_every_minute'])) {
                $schedules['duj_every_minute'] = [
                    'interval' => 60,
                    'display'  => __('Každou minutu (duj-wellness)', 'duj-wellness'),
                ];
            }
            return $schedules;
        });

        ExpireHoldsJob::schedule();
        SyncAccommodationJob::schedule();
    }

    public function runExpireHolds(): void
    {
        global $wpdb;
        $bookingSvc = $this->buildBookingService($wpdb);
        (new ExpireHoldsJob($bookingSvc))->run();
    }

    public function registerRestRoutes(): void
    {
        global $wpdb;

        $settings       = Settings::instance();
        $schedRepo      = new ScheduleRepository($wpdb);
        $accomRepo      = new AccommodationRepository();
        $priceRepo      = new PriceRepository($wpdb);
        $codeRepo       = new AccessCodeRepository($wpdb);

        $schedResolver  = new ScheduleResolver($schedRepo, $accomRepo);
        $pricingService = new PricingService($priceRepo);
        $cutoffPolicy   = CutoffPolicy::fromSettings($settings);
        $tierResolver   = new TierResolver($priceRepo, $codeRepo);

        $availSvc = new AvailabilityService($schedResolver, $pricingService, $cutoffPolicy, $settings);
        $codeSvc  = new AccessCodeService($tierResolver);

        (new AvailabilityController($availSvc, $tierResolver, $settings))->register();
        (new AccessCodeController($codeSvc, new RateLimiter()))->register();

        $bookingSvc    = $this->buildBookingService($wpdb);
        $bookingRepo   = new BookingRepository($wpdb);
        $stripeGateway = StripeGatewayFactory::create($settings);

        $notificationSvc = $this->buildNotificationService($wpdb, $settings);

        (new BookingsController(
            $bookingSvc,
            $tierResolver,
            new RateLimiter(maxAttempts: 20),
            $stripeGateway,
            $settings,
            $bookingRepo,
        ))->register();

        (new WebhooksController(
            new StripeWebhookHandler($stripeGateway, $bookingRepo, $bookingSvc, $notificationSvc),
            $settings,
        ))->register();

        (new ActionController(
            new ActionTokenService($wpdb),
            $bookingRepo,
            $bookingSvc,
            $notificationSvc,
        ))->register();
    }

    private function buildBookingService(\wpdb $wpdb): BookingService
    {
        $settings       = Settings::instance();
        $priceRepo      = new PriceRepository($wpdb);
        $schedRepo      = new ScheduleRepository($wpdb);
        $accomRepo      = new AccommodationRepository();
        $pricingService = new PricingService($priceRepo);
        $cutoffPolicy   = CutoffPolicy::fromSettings($settings);
        $schedResolver  = new ScheduleResolver($schedRepo, $accomRepo);

        return new BookingService(
            bookingRepo:         new BookingRepository($wpdb),
            itemRepo:            new BookingItemRepository($wpdb),
            dayLockRepo:         new DayLockRepository($wpdb),
            priceRepo:           $priceRepo,
            resourceRepo:        new ResourceRepository($wpdb),
            availabilityService: new AvailabilityService($schedResolver, $pricingService, $cutoffPolicy, $settings),
            pricingService:      $pricingService,
            settings:            $settings,
            wpdb:                $wpdb,
        );
    }

    public function runSyncAccommodation(): void
    {
        $syncSvc = new AccommodationSyncService(
            new AccommodationRepository(),
            new IcsParser(),
            new AccommodationClassifier(),
        );
        (new SyncAccommodationJob($syncSvc))->run();
    }

    private function buildNotificationService(\wpdb $wpdb, Settings $settings): NotificationService
    {
        return new NotificationService(
            $wpdb,
            $settings,
            new TemplateRenderer(),
            new IcsGenerator(),
            new ActionTokenService($wpdb),
            new EmailChannel($settings),
            new TelegramChannel($settings),
        );
    }

    public function settings(): Settings
    {
        return Settings::instance();
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness;

use Duj\Wellness\Accommodation\AccommodationClassifier;
use Duj\Wellness\Accommodation\AccommodationSyncService;
use Duj\Wellness\Accommodation\IcsParser;
use Duj\Wellness\Cron\DeployJob;
use Duj\Wellness\Cron\ExpireHoldsJob;
use Duj\Wellness\Cron\RetentionCleanupJob;
use Duj\Wellness\Cron\SyncAccommodationJob;
use Duj\Wellness\Domain\AccessCodeService;
use Duj\Wellness\Domain\AvailabilityService;
use Duj\Wellness\Domain\BookingService;
use Duj\Wellness\Domain\CutoffPolicy;
use Duj\Wellness\Domain\PricingService;
use Duj\Wellness\Domain\ScheduleResolver;
use Duj\Wellness\Domain\TierResolver;
use Duj\Wellness\Migrations\Migration001Initial;
use Duj\Wellness\Migrations\Migration002ScheduleOverrideModes;
use Duj\Wellness\Migrations\Migration003ReseedDefaults;
use Duj\Wellness\Migrations\Migration004BackfillManualBookingItems;
use Duj\Wellness\Migrations\MigrationRunner;
use Duj\Wellness\Repository\AccessCodeRepository;
use Duj\Wellness\Repository\AccommodationRepository;
use Duj\Wellness\Repository\BookingItemRepository;
use Duj\Wellness\Repository\BookingRepository;
use Duj\Wellness\Repository\DayLockRepository;
use Duj\Wellness\Repository\PriceRepository;
use Duj\Wellness\Repository\ResourceRepository;
use Duj\Wellness\Repository\ScheduleRepository;
use Duj\Wellness\Notification\ActionTokenService;
use Duj\Wellness\Notification\Channels\EmailChannel;
use Duj\Wellness\Notification\Channels\SmsChannel;
use Duj\Wellness\Notification\Channels\TelegramChannel;
use Duj\Wellness\Notification\IcsGenerator;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Notification\TemplateRenderer;
use Duj\Wellness\Admin\Menu;
use Duj\Wellness\Frontend\Assets;
use Duj\Wellness\Gdpr\GdprEraser;
use Duj\Wellness\Gdpr\GdprExporter;
use Duj\Wellness\Frontend\Shortcode;
use Duj\Wellness\Payment\StripeGatewayFactory;
use Duj\Wellness\Payment\StripeWebhookHandler;
use Duj\Wellness\Rest\AccessCodeController;
use Duj\Wellness\Rest\ActionController;
use Duj\Wellness\Rest\AdminAccommodationController;
use Duj\Wellness\Rest\AdminBookingsController;
use Duj\Wellness\Rest\AdminNotificationsController;
use Duj\Wellness\Rest\AdminPricingController;
use Duj\Wellness\Rest\AdminScheduleController;
use Duj\Wellness\Rest\AdminSettingsController;
use Duj\Wellness\Rest\AdminStatsController;
use Duj\Wellness\Rest\AdminTemplatesController;
use Duj\Wellness\Rest\AvailabilityController;
use Duj\Wellness\Rest\BookingsController;
use Duj\Wellness\Rest\DeployController;
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
        $runner->register(new Migration002ScheduleOverrideModes());
        $runner->register(new Migration003ReseedDefaults());
        $runner->register(new Migration004BackfillManualBookingItems());
        $runner->run();
    }

    private function registerHooks(): void
    {
        $this->registerAdmin();
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('init', [$this, 'registerCronSchedules']);
        add_action('init', [$this, 'registerFrontend']);
        add_action('init', [$this, 'registerGdpr']);
        add_action(ExpireHoldsJob::HOOK, [$this, 'runExpireHolds']);
        add_action(SyncAccommodationJob::HOOK, [$this, 'runSyncAccommodation']);
        add_action(RetentionCleanupJob::HOOK, [$this, 'runRetentionCleanup']);
        add_action(DeployJob::HOOK, [$this, 'runDeploy']);
    }

    public function registerGdpr(): void
    {
        (new GdprExporter())->register();
        (new GdprEraser())->register();
    }

    public function registerFrontend(): void
    {
        $settings = Settings::instance();
        $assets   = new Assets($settings, DUJ_WELLNESS_URL, DUJ_WELLNESS_VERSION);
        $assets->register();
        (new Shortcode($assets))->register();
    }

    public function registerAdmin(): void
    {
        (new Menu())->register();
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
        RetentionCleanupJob::schedule();
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

        // GitHub auto-deploy — public endpoint secured by HMAC-SHA256.
        (new DeployController())->register();

        // Admin-only controllers — registered unconditionally so they work even without Stripe.
        (new AdminScheduleController())->register();
        (new AdminPricingController())->register();
        (new AdminAccommodationController())->register();
        (new AdminTemplatesController())->register();
        (new AdminNotificationsController())->register();
        (new AdminSettingsController())->register();
        (new AdminStatsController())->register();

        // Booking + notification services — no Stripe dependency.
        try {
            $bookingRepo     = new BookingRepository($wpdb);
            $bookingSvc      = $this->buildBookingService($wpdb);
            $notificationSvc = $this->buildNotificationService($wpdb, $settings);

            (new AdminBookingsController($bookingRepo, $bookingSvc, $notificationSvc))->register();

            (new ActionController(
                new ActionTokenService($wpdb),
                $bookingRepo,
                $bookingSvc,
                $notificationSvc,
            ))->register();

            // BookingsController is always registered; Stripe gateway is optional.
            $stripeGateway = null;
            try {
                $stripeGateway = StripeGatewayFactory::create($settings);
            } catch (\Throwable $e) {
                error_log('[duj-wellness] Stripe init failed — payment via Stripe unavailable: ' . $e->getMessage());
            }

            (new BookingsController(
                $bookingSvc,
                $tierResolver,
                new RateLimiter(maxAttempts: 20),
                $stripeGateway,
                $settings,
                $bookingRepo,
                $notificationSvc,
            ))->register();

            // Stripe webhook controller — only when Stripe is available.
            if ($stripeGateway !== null) {
                (new WebhooksController(
                    new StripeWebhookHandler($stripeGateway, $bookingRepo, $bookingSvc, $notificationSvc),
                    $settings,
                ))->register();
            }
        } catch (\Throwable $e) {
            error_log('[duj-wellness] registerRestRoutes failed (booking/action/webhook controllers not registered): ' . $e->getMessage());
        }
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

    public function runRetentionCleanup(): void
    {
        (new RetentionCleanupJob())->run();
    }

    public function runDeploy(string $transientKey): void
    {
        (new DeployJob())->run($transientKey);
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
            new SmsChannel($settings),
        );
    }

    public function settings(): Settings
    {
        return Settings::instance();
    }
}

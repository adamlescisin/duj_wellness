<?php

declare(strict_types=1);

namespace Duj\Wellness;

use Duj\Wellness\Domain\AccessCodeService;
use Duj\Wellness\Domain\AvailabilityService;
use Duj\Wellness\Domain\CutoffPolicy;
use Duj\Wellness\Domain\PricingService;
use Duj\Wellness\Domain\ScheduleResolver;
use Duj\Wellness\Domain\TierResolver;
use Duj\Wellness\Migrations\Migration001Initial;
use Duj\Wellness\Migrations\MigrationRunner;
use Duj\Wellness\Repository\AccessCodeRepository;
use Duj\Wellness\Repository\AccommodationRepository;
use Duj\Wellness\Repository\PriceRepository;
use Duj\Wellness\Repository\ScheduleRepository;
use Duj\Wellness\Rest\AccessCodeController;
use Duj\Wellness\Rest\AvailabilityController;
use Duj\Wellness\Support\RateLimiter;
use Duj\Wellness\Support\Settings;

/**
 * Centrální bootstrap třídy. Registruje hooky a spravuje lifecycle.
 */
final class Plugin
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

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

    public function registerRestRoutes(): void
    {
        global $wpdb;

        $settings    = Settings::instance();
        $schedRepo   = new ScheduleRepository($wpdb);
        $accomRepo   = new AccommodationRepository($wpdb);
        $priceRepo   = new PriceRepository($wpdb);
        $codeRepo    = new AccessCodeRepository($wpdb);

        $schedResolver = new ScheduleResolver($schedRepo, $accomRepo);
        $pricingService = new PricingService($priceRepo);
        $cutoffPolicy  = new CutoffPolicy($settings);
        $tierResolver  = new TierResolver($priceRepo, $codeRepo);

        $availSvc = new AvailabilityService($schedResolver, $pricingService, $cutoffPolicy, $settings);
        $codeSvc  = new AccessCodeService($tierResolver);

        (new AvailabilityController($availSvc, $tierResolver, $settings))->register();
        (new AccessCodeController($codeSvc, new RateLimiter()))->register();
    }

    public function settings(): Settings
    {
        return Settings::instance();
    }
}

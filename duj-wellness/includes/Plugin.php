<?php

declare(strict_types=1);

namespace Duj\Wellness;

use Duj\Wellness\Migrations\Migration001Initial;
use Duj\Wellness\Migrations\MigrationRunner;
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
        // Budoucí fáze zde zaregistrují REST API, admin menu, shortcody atd.
        // Pro Fázi 0 pouze prázdný stub.
    }

    public function settings(): Settings
    {
        return Settings::instance();
    }
}

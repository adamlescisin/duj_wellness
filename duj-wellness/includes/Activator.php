<?php

declare(strict_types=1);

namespace Duj\Wellness;

use Duj\Wellness\Migrations\Migration001Initial;
use Duj\Wellness\Migrations\Migration002ScheduleOverrideModes;
use Duj\Wellness\Migrations\Migration003ReseedDefaults;
use Duj\Wellness\Migrations\MigrationRunner;

final class Activator
{
    public static function activate(): void
    {
        self::runMigrations();
        self::registerRoles();
        flush_rewrite_rules();
    }

    private static function runMigrations(): void
    {
        $runner = new MigrationRunner();
        $runner->register(new Migration001Initial());
        $runner->register(new Migration002ScheduleOverrideModes());
        $runner->register(new Migration003ReseedDefaults());
        $runner->run();
    }

    private static function registerRoles(): void
    {
        // Přidej roli správce wellness, pokud ještě neexistuje
        if (!get_role('duj_wellness_manager')) {
            add_role(
                'duj_wellness_manager',
                __('Správce wellness', 'duj-wellness'),
                ['read' => true, 'duj_manage_bookings' => true]
            );
        }

        // Přidej capability všem administrátorům
        $admins = get_users(['role' => 'administrator']);
        foreach ($admins as $admin) {
            $admin->add_cap('duj_manage_bookings');
        }
    }
}

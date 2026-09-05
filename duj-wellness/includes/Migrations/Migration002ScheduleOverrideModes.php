<?php

declare(strict_types=1);

namespace Duj\Wellness\Migrations;

/**
 * Rozšíří ENUM mode v duj_schedule_overrides o hodnoty open, custom, guests_only.
 * Původní ENUM('closed','replace') nestačil pro hromadné úpravy a výjimky rozvrhu.
 */
final class Migration002ScheduleOverrideModes implements MigrationInterface
{
    public function version(): int
    {
        return 2;
    }

    public function up(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_schedule_overrides';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE `{$table}` MODIFY COLUMN `mode`
             ENUM('closed','replace','open','custom','guests_only') NOT NULL DEFAULT 'closed'"
        );
    }
}

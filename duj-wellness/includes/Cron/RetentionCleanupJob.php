<?php

declare(strict_types=1);

namespace Duj\Wellness\Cron;

use Duj\Wellness\Support\Settings;

/**
 * Denní cron job pro GDPR retenci dat.
 *
 * Co dělá:
 * 1. Anonymizuje osobní data rezervací starších než gdpr_retention_months.
 * 2. Maže záznamy audit logu starší než 30 dnů.
 * 3. Maže notifikační záznamy starší než 90 dnů.
 * 4. Maže expirované action tokeny.
 */
final class RetentionCleanupJob
{
    public const HOOK = 'duj_wellness_retention_cleanup';

    public function run(): void
    {
        global $wpdb;

        $settings       = Settings::instance();
        $retentionMonths = max(1, (int) $settings->gdprRetentionMonths());
        $cutoff         = date('Y-m-d H:i:s', strtotime("-{$retentionMonths} months"));

        $this->anonymizeOldBookings($wpdb, $cutoff);
        $this->deleteOldAuditLog($wpdb);
        $this->deleteOldNotifications($wpdb);
        $this->deleteExpiredTokens($wpdb);
    }

    private function anonymizeOldBookings(\wpdb $wpdb, string $cutoff): void
    {
        $table = $wpdb->prefix . 'duj_bookings';

        $count = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                 SET customer_name  = NULL,
                     customer_email = '[anonymizováno]',
                     customer_phone = '[anonymizováno]',
                     customer_note  = NULL,
                     consent_ip     = NULL
                 WHERE created_at < %s
                   AND customer_email != '[anonymizováno]'",
                $cutoff
            )
        );

        if ($count > 0) {
            error_log("[duj-wellness] RetentionCleanupJob: anonymizováno {$count} rezervací.");
        }
    }

    private function deleteOldAuditLog(\wpdb $wpdb): void
    {
        $table  = $wpdb->prefix . 'duj_audit_log';
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        $count = $wpdb->query(
            $wpdb->prepare("DELETE FROM `{$table}` WHERE created_at < %s", $cutoff)
        );

        if ($count > 0) {
            error_log("[duj-wellness] RetentionCleanupJob: smazáno {$count} audit log záznamů.");
        }
    }

    private function deleteOldNotifications(\wpdb $wpdb): void
    {
        $table  = $wpdb->prefix . 'duj_notifications';
        $cutoff = date('Y-m-d H:i:s', strtotime('-90 days'));

        $wpdb->query(
            $wpdb->prepare("DELETE FROM `{$table}` WHERE created_at < %s", $cutoff)
        );
    }

    private function deleteExpiredTokens(\wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'duj_action_tokens';
        $wpdb->query(
            $wpdb->prepare("DELETE FROM `{$table}` WHERE expires_at < %s", date('Y-m-d H:i:s'))
        );
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }
}

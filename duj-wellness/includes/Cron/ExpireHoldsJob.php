<?php

declare(strict_types=1);

namespace Duj\Wellness\Cron;

use Duj\Wellness\Domain\BookingService;

/**
 * Cron job pro expiraci hold rezervací.
 * Registrován přes WordPress cron (wp-cron nebo WP-CLI cron).
 * V produkci doporučena Action Scheduler (viz Plugin.php hookování).
 */
final class ExpireHoldsJob
{
    public const HOOK = 'duj_wellness_expire_holds';

    public function __construct(private readonly BookingService $bookingService) {}

    public function run(): void
    {
        $count = $this->bookingService->expireHolds();
        if ($count > 0) {
            error_log("[duj-wellness] ExpireHoldsJob: expirováno {$count} rezervací.");
        }
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'duj_every_minute', self::HOOK);
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

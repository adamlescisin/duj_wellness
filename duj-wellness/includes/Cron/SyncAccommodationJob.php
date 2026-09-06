<?php

declare(strict_types=1);

namespace Duj\Wellness\Cron;

use Duj\Wellness\Accommodation\AccommodationSyncService;

/**
 * WP-Cron job pro hodinovou synchronizaci iCal feedu ubytování.
 */
final class SyncAccommodationJob
{
    public const HOOK = 'duj_wellness_sync_accommodation';

    public function __construct(
        private readonly AccommodationSyncService $syncService,
    ) {}

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        $count = $this->syncService->sync();
        // Nelogujeme URL ani obsah feedu
        if ($count > 0) {
            error_log('[duj-wellness] SyncAccommodationJob: synchronizováno ' . $count . ' dní.');
        }
    }
}

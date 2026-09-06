<?php

declare(strict_types=1);

namespace Duj\Wellness\Migrations;

use Duj\Wellness\Support\Settings;

/**
 * Backfills duj_booking_items for manually-created bookings that are missing them.
 * createManual() previously inserted only into duj_bookings, leaving no items rows,
 * which caused the overlap check (and frontend slot availability) to ignore them.
 */
final class Migration004BackfillManualBookingItems implements MigrationInterface
{
    public function version(): int
    {
        return 4;
    }

    public function up(): void
    {
        global $wpdb;

        $bookingsTable = $wpdb->prefix . 'duj_bookings';
        $itemsTable    = $wpdb->prefix . 'duj_booking_items';
        $resourceTable = $wpdb->prefix . 'duj_resources';

        // Find confirmed/awaiting_confirmation bookings with no items rows.
        $orphans = $wpdb->get_results(
            "SELECT b.id, b.uuid, b.booking_date, b.slot_from, b.slot_to, b.combo_key, b.created_at
             FROM `{$bookingsTable}` b
             LEFT JOIN `{$itemsTable}` bi ON bi.booking_id = b.id
             WHERE bi.id IS NULL
               AND b.status IN ('confirmed', 'awaiting_confirmation', 'pending_payment')",
            ARRAY_A
        ) ?? [];

        if (empty($orphans)) {
            return;
        }

        $tz            = new \DateTimeZone('Europe/Prague');
        $utcTz         = new \DateTimeZone('UTC');
        $bufferMinutes = Settings::instance()->bufferMinutes();
        $now           = current_time('mysql', true);

        // Build slug→id map for all resources.
        $resourceRows = $wpdb->get_results("SELECT id, slug FROM `{$resourceTable}`", ARRAY_A) ?? [];
        $slugToId     = array_column($resourceRows, 'id', 'slug');

        foreach ($orphans as $b) {
            $slugs = $this->comboToSlugs($b['combo_key']);
            if (empty($slugs)) {
                continue;
            }

            $slotFromFull = strlen($b['slot_from']) === 5 ? $b['slot_from'] . ':00' : $b['slot_from'];
            $slotToFull   = strlen($b['slot_to'])   === 5 ? $b['slot_to']   . ':00' : $b['slot_to'];

            $blockedFrom = (new \DateTimeImmutable("{$b['booking_date']} {$slotFromFull}", $tz))
                ->setTimezone($utcTz)->format('Y-m-d H:i:s');
            $blockedTo   = (new \DateTimeImmutable("{$b['booking_date']} {$slotToFull}", $tz))
                ->setTimezone($utcTz)
                ->modify("+{$bufferMinutes} minutes")
                ->format('Y-m-d H:i:s');

            foreach ($slugs as $slug) {
                $resourceId = $slugToId[$slug] ?? null;
                if ($resourceId === null) {
                    continue;
                }
                $wpdb->insert($itemsTable, [
                    'booking_id'     => (int) $b['id'],
                    'resource_id'    => (int) $resourceId,
                    'blocking_key'   => $b['uuid'],
                    'blocked_from'   => $blockedFrom,
                    'blocked_to'     => $blockedTo,
                    'buffer_minutes' => $bufferMinutes,
                    'created_at'     => $now,
                ]);
            }
        }
    }

    /** @return string[] */
    private function comboToSlugs(string $comboKey): array
    {
        return array_filter(
            array_map('trim', explode('+', $comboKey)),
            fn($s) => in_array($s, ['sud', 'sauna'], true)
        );
    }
}

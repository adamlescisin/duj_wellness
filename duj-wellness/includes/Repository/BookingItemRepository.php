<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

final class BookingItemRepository implements BookingItemRepositoryInterface
{
    public function __construct(private readonly \wpdb $wpdb) {}

    /**
     * Zapíše položky rezervace (booking items).
     * blocking_key musí být unikátní (nebo NULL).
     */
    public function insertItems(int $bookingId, array $items, string $nowUtc): void
    {
        $table = $this->wpdb->prefix . 'duj_booking_items';

        foreach ($items as $item) {
            $this->wpdb->insert($table, [
                'booking_id'     => $bookingId,
                'resource_id'    => $item['resource_id'],
                'blocking_key'   => $item['blocking_key'],
                'blocked_from'   => $item['blocked_from'],
                'blocked_to'     => $item['blocked_to'],
                'buffer_minutes' => $item['buffer_minutes'],
                'created_at'     => $nowUtc,
            ]);
        }
    }

    /**
     * Uvolní slot tím, že blocking_key nastaví na NULL.
     * Voláno při zrušení / expiraci.
     */
    public function releaseByBookingId(int $bookingId): void
    {
        $table = $this->wpdb->prefix . 'duj_booking_items';
        $this->wpdb->update(
            $table,
            ['blocking_key' => null],
            ['booking_id' => $bookingId]
        );
    }
}

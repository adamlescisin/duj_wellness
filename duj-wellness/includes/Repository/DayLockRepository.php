<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/**
 * Spravuje záznamy v duj_day_locks.
 * Zámky se berou v pořadí vzestupně dle resource_id, aby se předešlo deadlocku.
 */
final class DayLockRepository implements DayLockRepositoryInterface
{
    public function __construct(private readonly \wpdb $wpdb) {}

    /**
     * Zajistí existenci zámkového řádku a zamkne jej pomocí SELECT FOR UPDATE.
     * Volat uvnitř transakce.
     *
     * @param string $date        'Y-m-d'
     * @param int[]  $resourceIds Vzestupně seřazené resource ID
     */
    public function lockRows(string $date, array $resourceIds): void
    {
        $table = $this->wpdb->prefix . 'duj_day_locks';

        // Vložit chybějící řádky (idempotentně)
        foreach ($resourceIds as $rid) {
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT IGNORE INTO `{$table}` (lock_date, resource_id) VALUES (%s, %d)",
                    $date,
                    $rid
                )
            );
        }

        // SELECT FOR UPDATE — zamkne řádky pro tuto transakci
        $placeholders = implode(',', array_fill(0, count($resourceIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            $this->wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE lock_date = %s AND resource_id IN ($placeholders) ORDER BY resource_id FOR UPDATE",
                $date,
                ...$resourceIds
            )
        );
    }
}

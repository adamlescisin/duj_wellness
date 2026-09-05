<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

final class AccommodationRepository implements AccommodationRepositoryInterface
{
    public function findBlockForDate(string $date): ?AccommodationBlock
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE block_date = %s LIMIT 1",
                $date
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function findBlocksInRange(string $from, string $to): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE block_date BETWEEN %s AND %s ORDER BY block_date ASC",
                $from,
                $to
            ),
            ARRAY_A
        );

        return array_map([$this, 'hydrate'], $rows ?? []);
    }

    private function hydrate(array $row): AccommodationBlock
    {
        return new AccommodationBlock(
            blockDate: $row['block_date'],
            policy: $row['policy'],
            source: $row['source'],
            isManual: (bool) $row['is_manual'],
            synced_at: $row['synced_at'],
        );
    }
}

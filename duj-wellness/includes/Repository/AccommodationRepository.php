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

    public function upsertFromSync(array $blocks, string $syncedAt): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        foreach ($blocks as $block) {
            // Nikdy nepřepisuj manuální bloky
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT is_manual FROM `{$table}` WHERE block_date = %s LIMIT 1",
                    $block['block_date']
                ),
                ARRAY_A
            );

            if ($existing !== null && (bool) $existing['is_manual']) {
                continue;
            }

            if ($existing === null) {
                $wpdb->insert($table, [
                    'block_date' => $block['block_date'],
                    'policy'     => $block['policy'],
                    'source'     => 'ical',
                    'is_manual'  => 0,
                    'synced_at'  => $syncedAt,
                ]);
            } else {
                $wpdb->update(
                    $table,
                    ['policy' => $block['policy'], 'source' => 'ical', 'synced_at' => $syncedAt],
                    ['block_date' => $block['block_date']],
                );
            }
        }
    }

    public function setManualBlock(string $date, string $policy): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE block_date = %s LIMIT 1", $date),
            ARRAY_A
        );

        if ($existing === null) {
            $wpdb->insert($table, [
                'block_date' => $date,
                'policy'     => $policy,
                'source'     => 'manual',
                'is_manual'  => 1,
                'synced_at'  => null,
            ]);
        } else {
            $wpdb->update(
                $table,
                ['policy' => $policy, 'source' => 'manual', 'is_manual' => 1],
                ['block_date' => $date],
            );
        }
    }

    public function removeManualBlock(string $date): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        $wpdb->update(
            $table,
            ['is_manual' => 0, 'source' => 'ical'],
            ['block_date' => $date, 'is_manual' => 1],
        );
    }

    public function deleteSyncedBefore(string $before): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE is_manual = 0 AND block_date < %s",
                $before
            )
        );
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

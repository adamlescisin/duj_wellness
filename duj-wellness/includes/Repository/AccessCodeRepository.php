<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

final class AccessCodeRepository implements AccessCodeRepositoryInterface
{
    public function findActiveCode(string $code, string $date): ?AccessCodeRow
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_access_codes';

        // Porovnávej case-insensitive; ukládáme UPPER, dotazujeme UPPER.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE code = %s
                   AND is_active = 1
                   AND (valid_from IS NULL OR valid_from <= %s)
                   AND (valid_to   IS NULL OR valid_to   >= %s)
                   AND (max_uses   IS NULL OR used_count < max_uses)
                 LIMIT 1",
                strtoupper($code),
                $date,
                $date
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function incrementUsedCount(string $code): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_access_codes';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET used_count = used_count + 1 WHERE code = %s",
                strtoupper($code)
            )
        );
    }

    private function hydrate(array $row): AccessCodeRow
    {
        return new AccessCodeRow(
            id: (int) $row['id'],
            code: $row['code'],
            tierSlug: $row['tier_slug'],
            label: $row['label'],
            validFrom: $row['valid_from'] ?? null,
            validTo: $row['valid_to'] ?? null,
            maxUses: isset($row['max_uses']) ? (int) $row['max_uses'] : null,
            usedCount: (int) $row['used_count'],
            isActive: (bool) $row['is_active'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

final class ResourceRepository implements ResourceRepositoryInterface
{
    public function __construct(private readonly \wpdb $wpdb) {}

    public function findIdsBySlugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $table = $this->wpdb->prefix . 'duj_resources';
        $placeholders = implode(',', array_fill(0, count($slugs), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE slug IN ($placeholders) AND is_active = 1 ORDER BY id ASC",
                ...$slugs
            )
        ) ?? [];

        return array_map('intval', $rows);
    }
}

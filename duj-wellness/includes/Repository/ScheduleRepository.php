<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

final class ScheduleRepository implements ScheduleRepositoryInterface
{
    public function findRulesForWeekday(int $weekday, string $date): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_schedule_rules';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE weekday = %d
                   AND is_active = 1
                   AND (valid_from IS NULL OR valid_from <= %s)
                   AND (valid_to   IS NULL OR valid_to   >= %s)
                 ORDER BY time_from ASC",
                $weekday,
                $date,
                $date
            ),
            ARRAY_A
        );

        return array_map([$this, 'hydrateRule'], $rows ?? []);
    }

    public function findOverrideForDate(string $date): ?ScheduleOverride
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_schedule_overrides';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE override_date = %s LIMIT 1",
                $date
            ),
            ARRAY_A
        );

        return $row ? $this->hydrateOverride($row) : null;
    }

    private function hydrateRule(array $row): ScheduleRule
    {
        $scope = isset($row['resource_scope']) && $row['resource_scope'] !== null
            ? json_decode($row['resource_scope'], true)
            : null;

        return new ScheduleRule(
            id: (int) $row['id'],
            label: $row['label'],
            weekday: (int) $row['weekday'],
            timeFrom: $row['time_from'],
            timeTo: $row['time_to'],
            validFrom: $row['valid_from'],
            validTo: $row['valid_to'],
            resourceScope: $scope,
            isActive: (bool) $row['is_active'],
        );
    }

    private function hydrateOverride(array $row): ScheduleOverride
    {
        $slots = isset($row['slots']) && $row['slots'] !== null
            ? json_decode($row['slots'], true)
            : null;

        return new ScheduleOverride(
            id: (int) $row['id'],
            overrideDate: $row['override_date'],
            mode: $row['mode'],
            slots: $slots,
            note: $row['note'],
        );
    }
}

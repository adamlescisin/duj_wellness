<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

use Duj\Wellness\Domain\Slot;

interface ScheduleRepositoryInterface
{
    /** @return ScheduleRule[] */
    public function findRulesForWeekday(int $weekday, string $date): array;

    public function findOverrideForDate(string $date): ?ScheduleOverride;
}

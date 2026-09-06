<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/** DTO pro řádek z duj_schedule_rules. */
final class ScheduleRule
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $label,
        public readonly int $weekday,       // 1=Po … 7=Ne (ISO-8601)
        public readonly string $timeFrom,   // 'HH:MM:SS'
        public readonly string $timeTo,
        public readonly ?string $validFrom, // 'Y-m-d' nebo null
        public readonly ?string $validTo,
        public readonly ?array $resourceScope, // null = všechny, nebo ['sud']
        public readonly bool $isActive,
    ) {}
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/** DTO pro řádek z duj_schedule_overrides. */
final class ScheduleOverride
{
    public function __construct(
        public readonly int $id,
        public readonly string $overrideDate,
        public readonly string $mode,    // 'closed' | 'replace'
        public readonly ?array $slots,   // [['from'=>'15:00','to'=>'17:00','resources'=>['sud']]]
        public readonly ?string $note,
    ) {}
}

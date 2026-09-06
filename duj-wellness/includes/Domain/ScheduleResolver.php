<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

use Duj\Wellness\Repository\AccommodationRepositoryInterface;
use Duj\Wellness\Repository\ScheduleRepositoryInterface;

/**
 * Vypočítá dostupné sloty pro dané datum.
 *
 * Priorita: výjimka (override) > politika ubytování > pravidla rozvrhu.
 */
final class ScheduleResolver
{
    public function __construct(
        private readonly ScheduleRepositoryInterface $scheduleRepo,
        private readonly AccommodationRepositoryInterface $accommodationRepo,
    ) {}

    /**
     * Vrátí politiku dne.
     * 'ignore' | 'guests_only' | 'closed'
     *
     * Kontroluje i schedule override s mode='guests_only'.
     */
    public function resolveDayPolicy(string $date): string
    {
        $override = $this->scheduleRepo->findOverrideForDate($date);
        if ($override !== null && $override->mode === 'guests_only') {
            return 'guests_only';
        }

        $block = $this->accommodationRepo->findBlockForDate($date);
        return $block?->policy ?? 'ignore';
    }

    /**
     * Vrátí sloty pro dané datum.
     * Prázdné pole = den je zavřený.
     *
     * @return Slot[]
     */
    public function resolveSlots(string $date): array
    {
        // 1. Výjimka pro konkrétní datum (nejvyšší priorita)
        $override = $this->scheduleRepo->findOverrideForDate($date);
        if ($override !== null) {
            if ($override->mode === 'closed') {
                return [];
            }
            if ($override->mode === 'custom') {
                return $this->slotsFromOverrideSlots($override->slots ?? []);
            }
            // 'guests_only' and 'open': fall through to schedule rules below.
        }

        // 2. Politika ubytování
        $block = $this->accommodationRepo->findBlockForDate($date);
        if (($block?->policy ?? 'ignore') === 'closed') {
            return [];
        }

        // 3. Pravidla rozvrhu
        $tz = new \DateTimeZone('Europe/Prague');
        $weekday = (int) (new \DateTimeImmutable($date, $tz))->format('N');
        $rules = $this->scheduleRepo->findRulesForWeekday($weekday, $date);

        $slots = [];
        foreach ($rules as $rule) {
            $slots[] = new Slot(
                from: $rule->timeFrom,
                to: $rule->timeTo,
                resources: $rule->resourceScope,
            );
        }

        return $slots;
    }

    /** @param array $overrideSlots raw JSON array from override->slots */
    private function slotsFromOverrideSlots(array $overrideSlots): array
    {
        $slots = [];
        foreach ($overrideSlots as $s) {
            if (!isset($s['from'], $s['to'])) {
                continue;
            }
            $slots[] = new Slot(
                from: $s['from'],
                to: $s['to'],
                resources: $s['resources'] ?? null,
            );
        }
        return $slots;
    }
}

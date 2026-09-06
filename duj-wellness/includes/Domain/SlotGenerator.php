<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/**
 * Generátor slotů z časového okna.
 *
 * Z okna 16:00–21:00 / slotMinutes=120 / bufferMinutes=60 vyrobí:
 *   16:00–18:00 (pak 60 min pauza → 19:00)
 *   19:00–21:00 (pak 60 min pauza → 22:00 > 21:00 → konec)
 *
 * Pauza = technická pauza pro zatopení, úklid a výměnu ručníků.
 * Zákazníkovi se NIKDY nezobrazuje — je to jen prodloužení blocked_to.
 */
final class SlotGenerator
{
    /**
     * @param string $windowFrom    'HH:MM'
     * @param string $windowTo      'HH:MM'
     * @param int    $slotMinutes   délka slotu (výchozí 120)
     * @param int    $bufferMinutes technická pauza mezi sloty (výchozí 60)
     * @return Slot[]
     */
    public function generate(
        string $windowFrom,
        string $windowTo,
        int $slotMinutes,
        int $bufferMinutes,
    ): array {
        if ($slotMinutes <= 0) {
            throw new \InvalidArgumentException("slotMinutes musí být kladné číslo, dostáno: $slotMinutes");
        }

        $windowFromMin = $this->toMinutes($windowFrom);
        $windowToMin   = $this->toMinutes($windowTo);

        if ($windowFromMin >= $windowToMin) {
            return [];
        }

        $slots = [];
        $cursor = $windowFromMin;

        while (true) {
            $slotEnd = $cursor + $slotMinutes;

            // Slot se musí vejít celý do okna
            if ($slotEnd > $windowToMin) {
                break;
            }

            $slots[] = new Slot(
                from: $this->fromMinutes($cursor),
                to:   $this->fromMinutes($slotEnd),
            );

            // Posun kurzoru: konec slotu + technická pauza
            $cursor = $slotEnd + $bufferMinutes;
        }

        return $slots;
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return (int) $h * 60 + (int) $m;
    }

    private function fromMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }
}

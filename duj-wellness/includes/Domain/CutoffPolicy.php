<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/**
 * Uzávěrka rezervací.
 *
 * Pravidla dle spec:
 * - Slot, který už začal → vždy zamítnout.
 * - min_lead_minutes: minimální doba předem pro zatopení sudu — platí pro VŠECHNY hladiny.
 * - Cutoff 12:00: jen pro DNEŠNÍ DEN a jen pro hladinu s cutoff_mode='inherit'.
 * - Hladina 'guest' má cutoff_mode='lead_time_only' → kutoff 12:00 se na ni nevztahuje.
 */
final class CutoffPolicy
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $cutoffTime,       // '12:00'
        private readonly string $tzMode,           // 'wall_clock' | 'fixed_cet'
        private readonly int $minLeadMinutes,      // globální, per hladinu se přebíjí
    ) {}

    /**
     * Vrátí true, pokud je rezervace na $bookingDate $slotFrom pro $tier přípustná.
     *
     * @param string    $bookingDate 'Y-m-d'
     * @param string    $slotFrom    'HH:MM' nebo 'HH:MM:SS'
     * @param PriceTier $tier
     */
    public function allows(string $bookingDate, string $slotFrom, PriceTier $tier): bool
    {
        $tz  = new \DateTimeZone('Europe/Prague');
        $now = new \DateTimeImmutable('now', $tz);

        // Normalizuj slotFrom na HH:MM:SS
        $slotFromFull = strlen($slotFrom) === 5 ? $slotFrom . ':00' : $slotFrom;
        $slotStart = new \DateTimeImmutable("$bookingDate $slotFromFull", $tz);

        // Slot v minulosti (nebo právě začíná)
        if ($slotStart <= $now) {
            return false;
        }

        // Minimální doba předem (zatopení sudu) — per hladinu nebo globální
        $lead = $tier->minLeadMinutes ?? $this->minLeadMinutes;
        if ($lead > 0) {
            $earliest = $now->modify("+{$lead} minutes");
            if ($slotStart < $earliest) {
                return false;
            }
        }

        // Cutoff 12:00 se nevztahuje na hladiny s vlastní politikou uzávěrky
        if ($tier->cutoffMode !== 'inherit') {
            return true;
        }

        if (!$this->enabled) {
            return true;
        }

        // Cutoff 12:00 platí jen pro dnešní den
        $today = $now->format('Y-m-d');
        if ($bookingDate !== $today) {
            return true;
        }

        if ($this->tzMode === 'fixed_cet') {
            // Literální SEČ = UTC+1 celoročně (bez letního času)
            $deadline = new \DateTimeImmutable("$bookingDate {$this->cutoffTime}", new \DateTimeZone('+01:00'));
        } else {
            // Nástěnných 12:00 v Praze (doporučené — respektuje letní čas)
            $deadline = new \DateTimeImmutable("$bookingDate {$this->cutoffTime}", $tz);
        }

        return $now < $deadline;
    }

    public static function fromSettings(\Duj\Wellness\Support\Settings $settings): self
    {
        return new self(
            enabled: $settings->cutoffEnabled(),
            cutoffTime: $settings->cutoffTime(),
            tzMode: $settings->cutoffTzMode(),
            minLeadMinutes: $settings->minLeadTimeMinutes(),
        );
    }
}

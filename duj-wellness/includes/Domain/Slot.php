<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/** Hodnotový objekt pro časový slot. Časy jsou lokální (nástěnné, Europe/Prague). */
final class Slot
{
    public function __construct(
        public readonly string $from,       // 'HH:MM:SS' nebo 'HH:MM'
        public readonly string $to,
        public readonly ?array $resources = null,  // null = všechny zdroje, nebo ['sud', 'sauna']
    ) {}

    public function durationMinutes(): int
    {
        [$fh, $fm] = $this->parseParts($this->from);
        [$th, $tm] = $this->parseParts($this->to);
        return ($th * 60 + $tm) - ($fh * 60 + $fm);
    }

    /** Vrátí čas začátku slotu jako celé minuty od půlnoci. */
    public function fromMinutes(): int
    {
        [$h, $m] = $this->parseParts($this->from);
        return $h * 60 + $m;
    }

    /** Vrátí čas konce slotu jako celé minuty od půlnoci. */
    public function toMinutes(): int
    {
        [$h, $m] = $this->parseParts($this->to);
        return $h * 60 + $m;
    }

    private function parseParts(string $time): array
    {
        $parts = explode(':', $time);
        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }

    /** Formát HH:MM pro zobrazení zákazníkovi. */
    public function fromFormatted(): string
    {
        return substr($this->from, 0, 5);
    }

    public function toFormatted(): string
    {
        return substr($this->to, 0, 5);
    }
}

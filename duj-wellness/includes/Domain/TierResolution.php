<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/** Výsledek rozlišení hladiny z přístupového kódu. */
final class TierResolution
{
    public function __construct(
        public readonly PriceTier $tier,
        /** Normalizovaný kód (UPPER), pokud byl platný; jinak null. */
        public readonly ?string $validCode,
        /** true = kód byl zadán, ale není platný → zobrazit varování */
        public readonly bool $invalidCode,
    ) {}

    public function codeWasProvided(): bool
    {
        return $this->validCode !== null || $this->invalidCode;
    }
}

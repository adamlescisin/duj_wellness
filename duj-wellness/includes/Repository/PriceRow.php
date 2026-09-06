<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/** DTO pro řádek z duj_prices. */
final class PriceRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $tierSlug,
        public readonly string $comboKey,
        public readonly string $label,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $weekday,
        public readonly ?string $timeFrom,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly int $priority,
        public readonly bool $isActive,
    ) {}
}

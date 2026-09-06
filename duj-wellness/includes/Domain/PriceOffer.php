<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

final class PriceOffer
{
    public function __construct(
        public readonly string $comboKey,
        public readonly Money $price,
    ) {}

    public function toArray(): array
    {
        return [
            'combo_key'    => $this->comboKey,
            'amount_minor' => $this->price->amountMinor,
            'currency'     => $this->price->currency,
            'formatted'    => $this->price->format(),
        ];
    }
}

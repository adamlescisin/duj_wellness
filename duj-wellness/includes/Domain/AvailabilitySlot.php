<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

final class AvailabilitySlot
{
    /**
     * @param PriceOffer[] $offers
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $status,
        public readonly array $offers,
    ) {}

    public function toArray(): array
    {
        return [
            'from'   => $this->from,
            'to'     => $this->to,
            'status' => $this->status,
            'offers' => array_map(fn(PriceOffer $o) => $o->toArray(), $this->offers),
        ];
    }
}

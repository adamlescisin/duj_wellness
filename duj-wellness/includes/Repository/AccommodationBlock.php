<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/** DTO pro řádek z duj_accommodation_blocks. */
final class AccommodationBlock
{
    public function __construct(
        public readonly string $blockDate,
        /** 'ignore' | 'guests_only' | 'closed' */
        public readonly string $policy,
        public readonly string $source,
        public readonly bool $isManual,
        public readonly ?string $synced_at,
    ) {}
}

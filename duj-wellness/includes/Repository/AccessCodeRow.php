<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/** DTO pro řádek z duj_access_codes. */
final class AccessCodeRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $tierSlug,
        public readonly ?string $label,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly ?int $maxUses,
        public readonly int $usedCount,
        public readonly bool $isActive,
    ) {}
}

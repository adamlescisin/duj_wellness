<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/** Hodnotový objekt pro cenovou hladinu. */
final class PriceTier
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly bool $isDefault,
        public readonly bool $requiresCode,
        public readonly bool $showInForm,
        /** 'inherit' | 'lead_time_only' | 'none' */
        public readonly string $cutoffMode,
        /** null = převzít globální nastavení */
        public readonly ?int $minLeadMinutes,
        public readonly int $sortOrder,
        public readonly bool $isActive,
    ) {}
}

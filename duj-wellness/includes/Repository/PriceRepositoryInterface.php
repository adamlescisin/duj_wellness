<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

use Duj\Wellness\Domain\PriceTier;

interface PriceRepositoryInterface
{
    /** Vrátí všechny aktivní cenové hladiny seřazené podle sort_order. */
    public function findAllTiers(): array;

    public function findTierBySlug(string $slug): ?PriceTier;

    public function findDefaultTier(): ?PriceTier;

    /**
     * Vrátí cenu pro (tier, comboKey, date, slotFrom).
     * Vybírá aktivní záznam s nejvyšší priority vyhovující podmínkám.
     */
    public function resolvePrice(string $tierSlug, string $comboKey, string $date, string $slotFrom): ?PriceRow;

    /** @return PriceRow[] */
    public function findAllPrices(): array;
}

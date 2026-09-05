<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

use Duj\Wellness\Repository\PriceRepositoryInterface;

/**
 * Resolver ceny pro (tier, comboKey, date, slotFrom).
 *
 * Když pro hladinu cena chybí, spadne na výchozí hladinu a zaloguje varování.
 * Nikdy nevrací nulu.
 */
final class PricingService
{
    public function __construct(
        private readonly PriceRepositoryInterface $priceRepo,
    ) {}

    /**
     * Vrátí cenu pro kombinaci (tier, comboKey, date, slotFrom).
     *
     * @throws \RuntimeException pokud cena není ani ve výchozí hladině
     */
    public function resolvePrice(PriceTier $tier, string $comboKey, string $date, string $slotFrom): Money
    {
        $normalized = ComboKey::normalize($comboKey);

        // Primárně hledáme cenu pro požadovanou hladinu
        $row = $this->priceRepo->resolvePrice($tier->slug, $normalized, $date, $slotFrom);

        if ($row !== null) {
            return new Money($row->amountMinor, $row->currency);
        }

        // Fallback na výchozí hladinu
        if (!$tier->isDefault) {
            $defaultTier = $this->priceRepo->findDefaultTier();
            if ($defaultTier !== null) {
                error_log(sprintf(
                    '[duj-wellness] Cena nenalezena pro hladinu "%s" / combo "%s" / %s %s — používám výchozí hladinu "%s".',
                    $tier->slug, $normalized, $date, $slotFrom, $defaultTier->slug
                ));

                $fallbackRow = $this->priceRepo->resolvePrice($defaultTier->slug, $normalized, $date, $slotFrom);
                if ($fallbackRow !== null) {
                    return new Money($fallbackRow->amountMinor, $fallbackRow->currency);
                }
            }
        }

        throw new \RuntimeException(sprintf(
            'Cena není konfigurována pro kombinaci: hladina="%s", combo="%s", datum=%s, slot=%s',
            $tier->slug, $normalized, $date, $slotFrom
        ));
    }
}

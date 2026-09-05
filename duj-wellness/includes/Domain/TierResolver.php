<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

use Duj\Wellness\Repository\AccessCodeRepositoryInterface;
use Duj\Wellness\Repository\PriceRepositoryInterface;

/**
 * Určuje cenovou hladinu zákazníka z přístupového kódu.
 *
 * Bezpečnostní pravidlo: neplatný kód VŽDY vrátí výchozí hladinu s příznakem
 * invalid_code — nikdy nerozlišujeme "neexistuje" vs "vypršel" vs "vyčerpán",
 * aby nešlo hádáním kódů zjistit, co platí.
 */
final class TierResolver
{
    public function __construct(
        private readonly PriceRepositoryInterface $priceRepo,
        private readonly AccessCodeRepositoryInterface $accessCodeRepo,
    ) {}

    public function resolve(?string $accessCode, string $date): TierResolution
    {
        $defaultTier = $this->priceRepo->findDefaultTier();
        if ($defaultTier === null) {
            throw new \RuntimeException('Výchozí cenová hladina není konfigurována.');
        }

        if ($accessCode === null || trim($accessCode) === '') {
            return new TierResolution($defaultTier, null, false);
        }

        $codeRow = $this->accessCodeRepo->findActiveCode($accessCode, $date);

        if ($codeRow === null) {
            // Neplatný / vypršelý / vyčerpaný kód — vracíme výchozí hladinu
            return new TierResolution($defaultTier, null, true);
        }

        $tier = $this->priceRepo->findTierBySlug($codeRow->tierSlug);
        if ($tier === null) {
            return new TierResolution($defaultTier, null, true);
        }

        return new TierResolution($tier, $codeRow->code, false);
    }
}

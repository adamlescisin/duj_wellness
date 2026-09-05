<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/**
 * Validuje přístupový kód a vrací odpovídající cenovou hladinu.
 * Bezpečnostní pravidlo: neplatný kód VŽDY vrátí výchozí hladinu,
 * nikdy nerozlišujeme důvod zamítnutí.
 */
final class AccessCodeService
{
    public function __construct(
        private readonly TierResolver $tierResolver,
    ) {}

    public function validate(?string $code, string $date): TierResolution
    {
        return $this->tierResolver->resolve($code, $date);
    }
}

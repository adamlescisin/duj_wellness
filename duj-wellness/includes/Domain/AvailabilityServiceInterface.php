<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

interface AvailabilityServiceInterface
{
    /** @return AvailabilityDay[] */
    public function getAvailability(string $from, string $to, PriceTier $tier): array;
}

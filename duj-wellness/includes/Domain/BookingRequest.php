<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/** Vstupní DTO pro vytvoření rezervace. */
final class BookingRequest
{
    public function __construct(
        public readonly string $bookingDate,
        public readonly string $slotFrom,
        public readonly string $comboKey,
        public readonly string $customerEmail,
        public readonly string $customerPhone,
        public readonly ?string $customerName,
        public readonly ?string $customerNote,
        public readonly ?int $guests,
        public readonly string $paymentMethod,
        public readonly string $tierSlug,
        public readonly ?string $validCode,
        public readonly string $source,
        public readonly string $locale,
        /** IP adresa jako binary string z inet_pton() */
        public readonly ?string $consentIpBin,
    ) {}
}

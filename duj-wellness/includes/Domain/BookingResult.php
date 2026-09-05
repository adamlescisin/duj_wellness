<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/** Výsledek vytvoření rezervace. */
final class BookingResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $bookingId,
        public readonly ?string $uuid,
        public readonly ?string $reference,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {}

    public static function ok(int $bookingId, string $uuid, string $reference): self
    {
        return new self(true, $bookingId, $uuid, $reference, null, null);
    }

    public static function fail(string $errorCode, string $errorMessage): self
    {
        return new self(false, null, null, null, $errorCode, $errorMessage);
    }
}

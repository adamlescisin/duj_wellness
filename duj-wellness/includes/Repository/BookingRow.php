<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

/** DTO pro řádek z duj_bookings. */
final class BookingRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $reference,
        public readonly string $bookingDate,
        public readonly string $slotFrom,
        public readonly string $slotTo,
        public readonly string $comboKey,
        public readonly ?int $guests,
        public readonly string $status,
        public readonly string $tierSlug,
        public readonly ?string $accessCode,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $customerName,
        public readonly string $customerEmail,
        public readonly string $customerPhone,
        public readonly ?string $customerNote,
        public readonly ?string $adminNote,
        public readonly string $paymentMethod,
        public readonly string $paymentStatus,
        public readonly ?string $paymentProvider,
        public readonly ?string $paymentIntentId,
        public readonly ?array $paymentMeta,
        public readonly ?string $holdExpiresAt,
        public readonly ?string $authExpiresAt,
        public readonly ?string $confirmedAt,
        public readonly ?int $confirmedBy,
        public readonly ?string $cancelledAt,
        public readonly ?string $cancelReason,
        public readonly ?string $consentAt,
        public readonly string $source,
        public readonly string $locale,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}
}

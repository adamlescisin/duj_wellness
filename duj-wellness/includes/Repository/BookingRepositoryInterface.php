<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface BookingRepositoryInterface
{
    public function findById(int $id): ?BookingRow;
    public function findByUuid(string $uuid): ?BookingRow;
    public function findByReference(string $reference): ?BookingRow;
    public function findByPaymentIntentId(string $intentId): ?BookingRow;

    /** @return BookingRow[] */
    public function findExpiredHolds(\DateTimeImmutable $before): array;

    public function insert(array $data): int;
    public function update(int $id, array $data): void;

    /** Inkrementuje used_count přístupového kódu (voláno při přechodu do awaiting_confirmation). */
    public function incrementAccessCodeUsage(string $code): void;
}

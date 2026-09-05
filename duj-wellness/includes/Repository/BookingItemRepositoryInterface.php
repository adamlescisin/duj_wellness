<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface BookingItemRepositoryInterface
{
    public function insertItems(int $bookingId, array $items, string $nowUtc): void;
    public function releaseByBookingId(int $bookingId): void;
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

interface BookingServiceInterface
{
    public function create(BookingRequest $req): BookingResult;

    public function transition(int $bookingId, BookingStatus $to, array $extra = []): void;

    public function expireHolds(): int;
}

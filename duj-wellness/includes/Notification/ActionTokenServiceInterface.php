<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification;

interface ActionTokenServiceInterface
{
    public function create(int $bookingId, string $action): string;

    /** @return array{booking_id: int, action: string}|null */
    public function consume(string $token, string $clientIp): ?array;

    /** @return array{booking_id: int, action: string}|null */
    public function peek(string $token): ?array;
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

enum BookingStatus: string
{
    case PENDING_PAYMENT       = 'pending_payment';
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';
    case CONFIRMED             = 'confirmed';
    case COMPLETED             = 'completed';
    case EXPIRED               = 'expired';
    case REJECTED              = 'rejected';
    case CANCELLED             = 'cancelled';
    case NO_SHOW               = 'no_show';

    /** Matice povolených přechodů. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING_PAYMENT       => [self::AWAITING_CONFIRMATION, self::EXPIRED, self::CANCELLED],
            self::AWAITING_CONFIRMATION => [self::CONFIRMED, self::REJECTED, self::CANCELLED],
            self::CONFIRMED             => [self::COMPLETED, self::CANCELLED, self::NO_SHOW],
            self::COMPLETED             => [],
            self::EXPIRED               => [],
            self::REJECTED              => [],
            self::CANCELLED             => [],
            self::NO_SHOW               => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Blokující stavy = blocking_key je vyplněný (slot obsazený).
     * Neblokující = booking_key NULL (slot volný).
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::PENDING_PAYMENT,
            self::AWAITING_CONFIRMATION,
            self::CONFIRMED,
            self::NO_SHOW => true,
            default       => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING_PAYMENT       => __('Čeká na platbu', 'duj-wellness'),
            self::AWAITING_CONFIRMATION => __('Čeká na potvrzení', 'duj-wellness'),
            self::CONFIRMED             => __('Potvrzeno', 'duj-wellness'),
            self::COMPLETED             => __('Dokončeno', 'duj-wellness'),
            self::EXPIRED               => __('Expirováno', 'duj-wellness'),
            self::REJECTED              => __('Zamítnuto', 'duj-wellness'),
            self::CANCELLED             => __('Zrušeno', 'duj-wellness'),
            self::NO_SHOW               => __('Nedostavení se', 'duj-wellness'),
        };
    }
}

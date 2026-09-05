<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

final class AvailabilityDay
{
    /**
     * @param Slot[]             $slots          Raw slots from schedule
     * @param AvailabilitySlot[] $availableSlots Processed slots with offers
     */
    public function __construct(
        public readonly string $date,
        public readonly string $policy,
        public readonly array $slots,
        public readonly array $availableSlots,
    ) {}

    public function toArray(): array
    {
        return [
            'date'   => $this->date,
            'status' => $this->resolveStatus(),
            'slots'  => array_map(fn(AvailabilitySlot $s) => $s->toArray(), $this->availableSlots),
        ];
    }

    private function resolveStatus(): string
    {
        return match ($this->policy) {
            'past'        => 'past',
            'guests_only' => 'guests_only',
            'closed'      => 'closed',
            default       => empty($this->availableSlots) ? 'closed' : 'open',
        };
    }
}

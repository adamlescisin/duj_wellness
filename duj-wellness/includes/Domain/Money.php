<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/**
 * Hodnotový objekt pro peněžní částku.
 * Vždy v haléřích — žádné floaty, žádná zaokrouhlení.
 *
 * Stripe CZK je zero-decimal currency → toStripeAmount() vrací celé koruny.
 */
final class Money
{
    public function __construct(
        public readonly int $amountMinor,   // haléře: 1 500 Kč = 150 000
        public readonly string $currency = 'CZK',
    ) {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException("Částka nesmí být záporná: $amountMinor");
        }
    }

    /**
     * Stripe CZK = zero-decimal currency → posílá se v celých korunách.
     * 150 000 haléřů → 1 500 korun pro Stripe API.
     */
    public function toStripeAmount(): int
    {
        return intdiv($this->amountMinor, 100);
    }

    /** Formátuje pro zobrazení: 150000 → "1 500 Kč". */
    public function format(): string
    {
        $koruny = intdiv($this->amountMinor, 100);
        $halere = $this->amountMinor % 100;

        if ($halere === 0) {
            return number_format($koruny, 0, ',', "\u{00A0}") . "\u{00A0}Kč";
        }

        return number_format($koruny, 0, ',', "\u{00A0}") . ',' . str_pad((string) $halere, 2, '0') . "\u{00A0}Kč";
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor
            && $this->currency === $other->currency;
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Nelze sčítat různé měny: {$this->currency} a {$other->currency}");
        }
        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }
}

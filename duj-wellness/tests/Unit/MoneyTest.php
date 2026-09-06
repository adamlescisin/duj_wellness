<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** Non-breaking space used as thousands separator and before Kč */
    private const NBSP = "\u{00A0}";

    public function testFormatThousands(): void
    {
        $m = new Money(150000, 'CZK');
        $this->assertSame('1' . self::NBSP . '500' . self::NBSP . 'Kč', $m->format());
    }

    public function testFormatZero(): void
    {
        $m = new Money(0, 'CZK');
        $this->assertSame('0' . self::NBSP . 'Kč', $m->format());
    }

    public function testFormatSmall(): void
    {
        $m = new Money(10000, 'CZK');
        $this->assertSame('100' . self::NBSP . 'Kč', $m->format());
    }

    public function testFormatTwoThousand(): void
    {
        $m = new Money(200000, 'CZK');
        $this->assertSame('2' . self::NBSP . '000' . self::NBSP . 'Kč', $m->format());
    }

    public function testToStripeAmountCzk(): void
    {
        // CZK is zero-decimal in Stripe — send whole korunas
        $m = new Money(150000, 'CZK');
        $this->assertSame(1500, $m->toStripeAmount());
    }

    public function testToStripeAmountRoundDown(): void
    {
        // intdiv — haléře are truncated, not rounded
        $m = new Money(150099, 'CZK');
        $this->assertSame(1500, $m->toStripeAmount());
    }

    public function testAmountMinorIsStoredExactly(): void
    {
        $m = new Money(200000, 'CZK');
        $this->assertSame(200000, $m->amountMinor);
    }

    public function testCurrencyIsStoredExactly(): void
    {
        $m = new Money(100000, 'CZK');
        $this->assertSame('CZK', $m->currency);
    }

    public function testNegativeAmountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1, 'CZK');
    }

    public function testAddSameCurrency(): void
    {
        $a = new Money(100000, 'CZK');
        $b = new Money(50000, 'CZK');
        $this->assertSame(150000, $a->add($b)->amountMinor);
    }

    public function testAddDifferentCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(100000, 'CZK'))->add(new Money(100000, 'EUR'));
    }

    public function testEqualsTrue(): void
    {
        $a = new Money(150000, 'CZK');
        $b = new Money(150000, 'CZK');
        $this->assertTrue($a->equals($b));
    }

    public function testEqualsFalse(): void
    {
        $a = new Money(150000, 'CZK');
        $b = new Money(200000, 'CZK');
        $this->assertFalse($a->equals($b));
    }
}

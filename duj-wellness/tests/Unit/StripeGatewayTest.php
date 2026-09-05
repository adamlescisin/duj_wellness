<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Payment\StripeGateway;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

/**
 * Unit testy pro StripeGateway.
 *
 * ADR-3: CZK je u Stripe "zero-decimal" — do API posíláme celé Kč (intdiv haléřů / 100).
 * Spec: "⚠️ Ověř při implementaci v aktuální dokumentaci Stripe, jak Stripe očekává
 * částku v CZK (minor units vs. celé koruny) — u některých měn platila v minulosti výjimka.
 * Napiš na to unit test."
 */
final class StripeGatewayTest extends TestCase
{
    private StripeGateway $gateway;

    protected function setUp(): void
    {
        // StripeClient je mockován — neděláme síťová volání
        $this->gateway = new StripeGateway($this->createMock(StripeClient::class));
    }

    // --- CZK zero-decimal konverze (klíčový test dle spec) ---

    public function testCzkConvertedToWholeCrowns(): void
    {
        // 150000 haléřů = 1500 Kč → Stripe dostane 1500
        $this->assertSame(1500, $this->gateway->toStripeAmount(150000, 'CZK'));
    }

    public function testCzkLowercase(): void
    {
        $this->assertSame(1500, $this->gateway->toStripeAmount(150000, 'czk'));
    }

    public function testCzkZero(): void
    {
        $this->assertSame(0, $this->gateway->toStripeAmount(0, 'czk'));
    }

    public function testCzkOddHalere(): void
    {
        // 150050 haléřů = 1500 Kč (intdiv → 1500, 50 haléřů se zahodí)
        $this->assertSame(1500, $this->gateway->toStripeAmount(150050, 'czk'));
    }

    public function testCzkMaxAmount(): void
    {
        // 200000 haléřů = 2000 Kč
        $this->assertSame(2000, $this->gateway->toStripeAmount(200000, 'czk'));
    }

    // --- Ostatní měny jsou v minor units (cents) ---

    public function testEurPassedThrough(): void
    {
        // EUR: 1500 centů = 15,00 € → posíláme 1500 (minor units)
        $this->assertSame(1500, $this->gateway->toStripeAmount(1500, 'EUR'));
    }

    public function testUsdPassedThrough(): void
    {
        $this->assertSame(2000, $this->gateway->toStripeAmount(2000, 'USD'));
    }

    // --- Ceny z ceníku (spec) ---

    public function testPublicTierSud(): void
    {
        // Wellness sud: 1500 Kč public = 150000 haléřů → Stripe: 1500
        $this->assertSame(1500, $this->gateway->toStripeAmount(150000, 'czk'));
    }

    public function testPublicTierCombo(): void
    {
        // Sauna+sud: 2000 Kč = 200000 haléřů → Stripe: 2000
        $this->assertSame(2000, $this->gateway->toStripeAmount(200000, 'czk'));
    }

    public function testGuestTierSud(): void
    {
        // Guest tier sud: 1000 Kč = 100000 haléřů → Stripe: 1000
        $this->assertSame(1000, $this->gateway->toStripeAmount(100000, 'czk'));
    }
}

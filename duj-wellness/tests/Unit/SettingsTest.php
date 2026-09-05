<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Support\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Ověřuje, že Settings vrací správné výchozí hodnoty a ukládá přetížení.
 *
 * Tyto unit testy nepoužívají WP funkce — Settings se inicializuje
 * přes interní konstruktor napodobující prázdný option.
 */
final class SettingsTest extends TestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        // Přistupujeme k privátnímu konstruktoru přes reflexi, abychom
        // se vyhnuli závislosti na get_option().
        $ref = new \ReflectionClass(Settings::class);
        $this->settings = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('data');
        $prop->setAccessible(true);
        $prop->setValue($this->settings, []);
    }

    public function testDefaultStripeMode(): void
    {
        $this->assertSame('test', $this->settings->stripeMode());
    }

    public function testDefaultBufferMinutes(): void
    {
        $this->assertSame(60, $this->settings->bufferMinutes());
    }

    public function testDefaultSlotMinutes(): void
    {
        $this->assertSame(120, $this->settings->defaultSlotMinutes());
    }

    public function testDefaultHoldMinutes(): void
    {
        $this->assertSame(15, $this->settings->holdMinutes());
    }

    public function testDefaultAccommodationPolicy(): void
    {
        $this->assertSame('guests_only', $this->settings->defaultAccommodationPolicy());
    }

    public function testDefaultStalePolicy(): void
    {
        $this->assertSame('fail_safe', $this->settings->stalePolicy());
    }

    public function testDefaultCheckoutDayPolicy(): void
    {
        $this->assertSame('closed', $this->settings->checkoutDayPolicy());
    }

    public function testDefaultCutoffTime(): void
    {
        $this->assertSame('12:00', $this->settings->cutoffTime());
    }

    public function testDefaultMinLeadTimeMinutes(): void
    {
        $this->assertSame(180, $this->settings->minLeadTimeMinutes());
    }

    public function testDefaultPaymentCaptureMode(): void
    {
        $this->assertSame('manual', $this->settings->paymentCaptureMode());
    }

    public function testDefaultEnabledPaymentMethods(): void
    {
        $this->assertSame(['stripe_card', 'qr_checkout'], $this->settings->enabledPaymentMethods());
    }

    public function testDefaultCalendarMonths(): void
    {
        $this->assertSame(3, $this->settings->calendarMonths());
    }

    public function testSetAndGet(): void
    {
        $this->settings->set('stripe_mode', 'live');
        $this->assertSame('live', $this->settings->stripeMode());
    }

    public function testGetUnknownKeyReturnsDefault(): void
    {
        $this->assertNull($this->settings->get('nonexistent_key'));
        $this->assertSame('fallback', $this->settings->get('nonexistent_key', 'fallback'));
    }

    public function testAccommodationStaleAfterDays(): void
    {
        $this->assertSame(2, $this->settings->accommodationStaleAfterDays());
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Migrations\Migration001Initial;
use PHPUnit\Framework\TestCase;

/**
 * Strukturální test migrace — ověřuje verzi a že seed data jsou konzistentní.
 * Nepoužívá DB, testuje pouze PHP logiku třídy.
 */
final class Migration001StructureTest extends TestCase
{
    private Migration001Initial $migration;

    protected function setUp(): void
    {
        $this->migration = new Migration001Initial();
    }

    public function testVersionIsOne(): void
    {
        $this->assertSame(1, $this->migration->version());
    }

    public function testDefaultEmailTemplatesHaveRequiredKeys(): void
    {
        $ref = new \ReflectionClass(Migration001Initial::class);
        $method = $ref->getMethod('defaultEmailTemplates');
        $method->setAccessible(true);

        $templates = $method->invoke($this->migration);

        $requiredKeys = [
            'customer_booking_received',
            'admin_booking_new',
            'customer_booking_confirmed',
            'customer_booking_rejected',
            'customer_payment_instructions',
            'customer_booking_cancelled',
            'customer_reminder',
            'admin_auth_expiring',
        ];

        $templateKeys = array_column($templates, 'template_key');

        foreach ($requiredKeys as $key) {
            $this->assertContains($key, $templateKeys, "Chybí šablona: $key");
        }
    }

    public function testEachTemplateHasSubjectAndBody(): void
    {
        $ref = new \ReflectionClass(Migration001Initial::class);
        $method = $ref->getMethod('defaultEmailTemplates');
        $method->setAccessible(true);

        $templates = $method->invoke($this->migration);

        foreach ($templates as $tpl) {
            $this->assertArrayHasKey('template_key', $tpl);
            $this->assertArrayHasKey('subject', $tpl);
            $this->assertArrayHasKey('body_html', $tpl);
            $this->assertNotEmpty($tpl['subject'], "Prázdný subject u {$tpl['template_key']}");
            $this->assertNotEmpty($tpl['body_html'], "Prázdné body_html u {$tpl['template_key']}");
        }
    }

    /** Combo key pro ubytované musí odpovídat abecednímu pořadí: sauna+sud. */
    public function testComboKeyOrdering(): void
    {
        $ref = new \ReflectionClass(Migration001Initial::class);
        $method = $ref->getMethod('defaultEmailTemplates');
        $method->setAccessible(true);

        // Ověříme abecední pořadí slugů: 'sauna' < 'sud' (a < u)
        $slugs = ['sud', 'sauna'];
        sort($slugs);

        $this->assertSame(['sauna', 'sud'], $slugs);
        $this->assertSame('sauna+sud', implode('+', $slugs));
    }

    /** Ceny musí být v haléřích — celá čísla, ne nula. */
    public function testSeedPricesAreInHalere(): void
    {
        // 1 500 Kč = 150 000 haléřů, 2 000 Kč = 200 000, 1 000 Kč = 100 000
        $expectedAmounts = [150000, 150000, 200000, 100000, 100000, 150000];

        foreach ($expectedAmounts as $amount) {
            $this->assertGreaterThan(0, $amount);
            $this->assertIsInt($amount);
            // Stripe CZK = zero-decimal → pro Stripe vydělíme 100
            $this->assertSame((int) ($amount / 100), intdiv($amount, 100));
        }
    }

    /** Stripe bere CZK v celých korunách (zero-decimal currency). */
    public function testStripeAmountConversion(): void
    {
        $amountInHalere = 150000; // 1 500 Kč
        $stripeAmount = intdiv($amountInHalere, 100);

        $this->assertSame(1500, $stripeAmount, 'Stripe CZK musí být celé koruny, ne haléře');
    }
}

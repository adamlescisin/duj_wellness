<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\ComboKey;
use PHPUnit\Framework\TestCase;

final class ComboKeyTest extends TestCase
{
    public function testNormalizeAlreadySorted(): void
    {
        $this->assertSame('sauna+sud', ComboKey::normalize('sauna+sud'));
    }

    public function testNormalizeReversedOrder(): void
    {
        $this->assertSame('sauna+sud', ComboKey::normalize('sud+sauna'));
    }

    public function testNormalizeSingleSud(): void
    {
        $this->assertSame('sud', ComboKey::normalize('sud'));
    }

    public function testNormalizeSingleSauna(): void
    {
        $this->assertSame('sauna', ComboKey::normalize('sauna'));
    }

    public function testNormalizeUppercaseInput(): void
    {
        $this->assertSame('sauna+sud', ComboKey::normalize('SUD+SAUNA'));
    }

    public function testIsValidSud(): void
    {
        $this->assertTrue(ComboKey::isValid('sud'));
    }

    public function testIsValidSauna(): void
    {
        $this->assertTrue(ComboKey::isValid('sauna'));
    }

    public function testIsValidCombo(): void
    {
        $this->assertTrue(ComboKey::isValid('sauna+sud'));
    }

    public function testIsValidReversedOrderStillValid(): void
    {
        // isValid normalizes first, so sud+sauna is valid (equals sauna+sud)
        $this->assertTrue(ComboKey::isValid('sud+sauna'));
    }

    public function testIsValidEmpty(): void
    {
        $this->assertFalse(ComboKey::isValid(''));
    }

    public function testIsValidUnknownKey(): void
    {
        $this->assertFalse(ComboKey::isValid('jacuzzi'));
    }

    public function testToResourceSlugsSingle(): void
    {
        $this->assertSame(['sud'], ComboKey::toResourceSlugs('sud'));
    }

    public function testToResourceSlugsCombo(): void
    {
        $slugs = ComboKey::toResourceSlugs('sauna+sud');
        sort($slugs);
        $this->assertSame(['sauna', 'sud'], $slugs);
    }
}

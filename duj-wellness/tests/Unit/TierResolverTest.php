<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\PriceTier;
use Duj\Wellness\Domain\TierResolver;
use Duj\Wellness\Repository\AccessCodeRepositoryInterface;
use Duj\Wellness\Repository\AccessCodeRow;
use Duj\Wellness\Repository\PriceRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class TierResolverTest extends TestCase
{
    private function makeDefaultTier(): PriceTier
    {
        return new PriceTier(
            slug: 'public',
            label: 'Veřejnost',
            isDefault: true,
            requiresCode: false,
            showInForm: true,
            cutoffMode: 'inherit',
            minLeadMinutes: null,
            sortOrder: 0,
            isActive: true,
        );
    }

    private function makeGuestTier(): PriceTier
    {
        return new PriceTier(
            slug: 'guest',
            label: 'Host',
            isDefault: false,
            requiresCode: true,
            showInForm: true,
            cutoffMode: 'lead_time_only',
            minLeadMinutes: null,
            sortOrder: 1,
            isActive: true,
        );
    }

    private function makeResolver(
        ?PriceTier $defaultTier = null,
        ?AccessCodeRow $codeRow = null,
        ?PriceTier $codeTier = null,
    ): TierResolver {
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $priceRepo->method('findDefaultTier')->willReturn($defaultTier ?? $this->makeDefaultTier());
        $priceRepo->method('findTierBySlug')->willReturn($codeTier);

        $codeRepo = $this->createMock(AccessCodeRepositoryInterface::class);
        $codeRepo->method('findActiveCode')->willReturn($codeRow);

        return new TierResolver($priceRepo, $codeRepo);
    }

    public function testNoCodeReturnsDefaultTier(): void
    {
        $resolver = $this->makeResolver();
        $result = $resolver->resolve(null, '2025-01-01');

        $this->assertSame('public', $result->tier->slug);
        $this->assertNull($result->validCode);
        $this->assertFalse($result->invalidCode);
    }

    public function testEmptyCodeReturnsDefaultTier(): void
    {
        $resolver = $this->makeResolver();
        $result = $resolver->resolve('  ', '2025-01-01');

        $this->assertSame('public', $result->tier->slug);
        $this->assertFalse($result->invalidCode);
    }

    public function testInvalidCodeReturnsDefaultTierWithFlag(): void
    {
        $resolver = $this->makeResolver(codeRow: null);
        $result = $resolver->resolve('BADCODE', '2025-01-01');

        $this->assertSame('public', $result->tier->slug);
        $this->assertNull($result->validCode);
        $this->assertTrue($result->invalidCode);
    }

    public function testValidCodeReturnsGuestTier(): void
    {
        $codeRow = new AccessCodeRow(
            id: 1,
            code: 'HOSTE2026',
            tierSlug: 'guest',
            label: null,
            validFrom: null,
            validTo: null,
            maxUses: 10,
            usedCount: 0,
            isActive: true,
        );

        $resolver = $this->makeResolver(codeRow: $codeRow, codeTier: $this->makeGuestTier());
        $result = $resolver->resolve('HOSTE2026', '2025-01-01');

        $this->assertSame('guest', $result->tier->slug);
        $this->assertSame('HOSTE2026', $result->validCode);
        $this->assertFalse($result->invalidCode);
    }

    public function testCodeWithMissingTierFallsBackToDefault(): void
    {
        $codeRow = new AccessCodeRow(
            id: 1,
            code: 'ORPHAN',
            tierSlug: 'nonexistent',
            label: null,
            validFrom: null,
            validTo: null,
            maxUses: 10,
            usedCount: 0,
            isActive: true,
        );

        // codeTier = null simulates findTierBySlug returning null
        $resolver = $this->makeResolver(codeRow: $codeRow, codeTier: null);
        $result = $resolver->resolve('ORPHAN', '2025-01-01');

        $this->assertSame('public', $result->tier->slug);
        $this->assertTrue($result->invalidCode);
    }

    public function testCodeWasProvidedTrueWhenValid(): void
    {
        $codeRow = new AccessCodeRow(
            id: 1,
            code: 'HOSTE2026',
            tierSlug: 'guest',
            label: null,
            validFrom: null,
            validTo: null,
            maxUses: null,
            usedCount: 0,
            isActive: true,
        );

        $resolver = $this->makeResolver(codeRow: $codeRow, codeTier: $this->makeGuestTier());
        $result = $resolver->resolve('HOSTE2026', '2025-01-01');

        $this->assertTrue($result->codeWasProvided());
    }

    public function testCodeWasProvidedTrueWhenInvalid(): void
    {
        $resolver = $this->makeResolver(codeRow: null);
        $result = $resolver->resolve('GARBAGE', '2025-01-01');

        $this->assertTrue($result->codeWasProvided());
    }

    public function testCodeWasProvidedFalseWhenNoCode(): void
    {
        $resolver = $this->makeResolver();
        $result = $resolver->resolve(null, '2025-01-01');

        $this->assertFalse($result->codeWasProvided());
    }
}

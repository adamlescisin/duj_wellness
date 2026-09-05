<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\PriceTier;
use Duj\Wellness\Domain\PricingService;
use Duj\Wellness\Repository\PriceRepositoryInterface;
use Duj\Wellness\Repository\PriceRow;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    private function makeTier(string $slug = 'public', bool $isDefault = true): PriceTier
    {
        return new PriceTier(
            slug: $slug,
            label: 'Test',
            isDefault: $isDefault,
            requiresCode: false,
            showInForm: true,
            cutoffMode: 'inherit',
            minLeadMinutes: null,
            sortOrder: 0,
            isActive: true,
        );
    }

    private function makePriceRow(int $amount = 150000, string $slug = 'public', string $combo = 'sud'): PriceRow
    {
        return new PriceRow(
            id: 1,
            tierSlug: $slug,
            comboKey: $combo,
            label: 'Test',
            amountMinor: $amount,
            currency: 'CZK',
            weekday: null,
            timeFrom: null,
            validFrom: null,
            validTo: null,
            priority: 0,
            isActive: true,
        );
    }

    public function testReturnsPriceForTier(): void
    {
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $priceRepo->method('resolvePrice')->willReturn($this->makePriceRow(150000));

        $service = new PricingService($priceRepo);
        $money = $service->resolvePrice($this->makeTier(), 'sud', '2025-01-01', '16:00');

        $this->assertSame(150000, $money->amountMinor);
        $this->assertSame('CZK', $money->currency);
    }

    public function testNormalizesComboKey(): void
    {
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $priceRepo->expects($this->once())
            ->method('resolvePrice')
            ->with('public', 'sauna+sud', $this->anything(), $this->anything())
            ->willReturn($this->makePriceRow(200000, 'public', 'sauna+sud'));

        $service = new PricingService($priceRepo);
        $service->resolvePrice($this->makeTier(), 'sud+sauna', '2025-01-01', '16:00');
    }

    public function testFallsBackToDefaultTierWhenPriceNotFound(): void
    {
        $defaultTier = $this->makeTier('public', true);
        $guestTier   = $this->makeTier('guest', false);

        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $priceRepo->method('resolvePrice')
            ->willReturnCallback(function (string $slug) {
                if ($slug === 'public') {
                    return $this->makePriceRow(150000, 'public', 'sud');
                }
                return null;
            });
        $priceRepo->method('findDefaultTier')->willReturn($defaultTier);

        $service = new PricingService($priceRepo);
        $money = $service->resolvePrice($guestTier, 'sud', '2025-01-01', '16:00');

        $this->assertSame(150000, $money->amountMinor);
    }

    public function testThrowsWhenNoPriceFound(): void
    {
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $priceRepo->method('resolvePrice')->willReturn(null);
        $priceRepo->method('findDefaultTier')->willReturn(null);

        $service = new PricingService($priceRepo);

        $this->expectException(\RuntimeException::class);
        $service->resolvePrice($this->makeTier(), 'sud', '2025-01-01', '16:00');
    }
}

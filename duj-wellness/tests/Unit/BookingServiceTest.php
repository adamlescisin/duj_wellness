<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\AvailabilityServiceInterface;
use Duj\Wellness\Domain\BookingRequest;
use Duj\Wellness\Domain\BookingService;
use Duj\Wellness\Domain\BookingStatus;
use Duj\Wellness\Domain\Money;
use Duj\Wellness\Domain\PriceTier;
use Duj\Wellness\Domain\PricingService;
use Duj\Wellness\Repository\BookingItemRepositoryInterface;
use Duj\Wellness\Repository\BookingRepositoryInterface;
use Duj\Wellness\Repository\BookingRow;
use Duj\Wellness\Repository\DayLockRepositoryInterface;
use Duj\Wellness\Repository\PriceRepositoryInterface;
use Duj\Wellness\Repository\ResourceRepositoryInterface;
use Duj\Wellness\Support\SettingsInterface;
use PHPUnit\Framework\TestCase;

final class BookingServiceTest extends TestCase
{
    private function makeSettings(int $bufferMinutes = 60, int $holdMinutes = 15): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('bufferMinutes')->willReturn($bufferMinutes);
        $settings->method('holdMinutes')->willReturn($holdMinutes);
        return $settings;
    }

    private function makePriceTier(string $slug = 'public'): PriceTier
    {
        return new PriceTier(
            slug: $slug,
            label: 'Test',
            isDefault: true,
            requiresCode: false,
            showInForm: true,
            cutoffMode: 'inherit',
            minLeadMinutes: null,
            sortOrder: 0,
            isActive: true,
        );
    }

    private function makeBookingRow(int $id = 1, string $status = 'pending_payment', ?string $accessCode = null): BookingRow
    {
        return new BookingRow(
            id:              $id,
            uuid:            'test-uuid-1234',
            reference:       'W20250101ABC',
            bookingDate:     '2025-01-01',
            slotFrom:        '16:00',
            slotTo:          '18:00',
            comboKey:        'sud',
            guests:          2,
            status:          $status,
            tierSlug:        'public',
            accessCode:      $accessCode,
            amountMinor:     150000,
            currency:        'CZK',
            customerName:    'Test User',
            customerEmail:   'test@example.com',
            customerPhone:   '+420123456789',
            customerNote:    null,
            adminNote:       null,
            paymentMethod:   'stripe_card',
            paymentStatus:   'pending',
            paymentProvider: null,
            paymentIntentId: null,
            paymentMeta:     null,
            holdExpiresAt:   null,
            authExpiresAt:   null,
            confirmedAt:     null,
            confirmedBy:     null,
            cancelledAt:     null,
            cancelReason:    null,
            consentAt:       null,
            source:          'web',
            locale:          'cs_CZ',
            createdAt:       '2025-01-01 10:00:00',
            updatedAt:       '2025-01-01 10:00:00',
        );
    }

    private function makeRequest(string $combo = 'sud'): BookingRequest
    {
        return new BookingRequest(
            bookingDate:   '2026-12-01',
            slotFrom:      '16:00',
            comboKey:      $combo,
            customerEmail: 'test@example.com',
            customerPhone: '+420123456789',
            customerName:  'Test',
            customerNote:  null,
            guests:        2,
            paymentMethod: 'stripe_card',
            tierSlug:      'public',
            validCode:     null,
            source:        'web',
            locale:        'cs_CZ',
            consentIpBin:  null,
        );
    }

    private function buildService(
        BookingRepositoryInterface $bookingRepo,
        ?ResourceRepositoryInterface $resourceRepo = null,
        ?PriceRepositoryInterface $priceRepo = null,
        ?\wpdb $wpdb = null,
    ): BookingService {
        if ($resourceRepo === null) {
            $resourceRepo = $this->createMock(ResourceRepositoryInterface::class);
            $resourceRepo->method('findIdsBySlugs')->willReturn([1]);
        }

        if ($priceRepo === null) {
            $priceRepo = $this->createMock(PriceRepositoryInterface::class);
            $tier = $this->makePriceTier();
            $priceRepo->method('findTierBySlug')->willReturn($tier);
            $priceRow = new \Duj\Wellness\Repository\PriceRow(1, 'public', 'sud', 'Test', 150000, 'CZK', null, null, null, null, 0, true);
            $priceRepo->method('resolvePrice')->willReturn($priceRow);
        }

        $pricingService = new PricingService($priceRepo);

        $itemRepo = $this->createMock(BookingItemRepositoryInterface::class);
        $dayLock  = $this->createMock(DayLockRepositoryInterface::class);
        $availSvc = $this->createMock(AvailabilityServiceInterface::class);
        $settings = $this->makeSettings();

        if ($wpdb === null) {
            $wpdb = $this->createMock(\wpdb::class);
            $wpdb->prefix = 'wp_';
            $wpdb->method('get_var')->willReturn('0'); // no overlap
            $wpdb->method('prepare')->willReturnCallback(fn($q, ...$args) => $q);
            $wpdb->method('query')->willReturn(true);
        }

        return new BookingService(
            bookingRepo:         $bookingRepo,
            itemRepo:            $itemRepo,
            dayLockRepo:         $dayLock,
            priceRepo:           $priceRepo,
            resourceRepo:        $resourceRepo,
            availabilityService: $availSvc,
            pricingService:      $pricingService,
            settings:            $settings,
            wpdb:                $wpdb,
        );
    }

    public function testTransitionAllowedStatusChange(): void
    {
        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        $bookingRepo->method('findById')->willReturn($this->makeBookingRow(1, 'pending_payment'));
        $bookingRepo->expects($this->once())
            ->method('update')
            ->with(1, $this->callback(fn($data) => $data['status'] === 'awaiting_confirmation'));

        $itemRepo = $this->createMock(BookingItemRepositoryInterface::class);
        $dayLock  = $this->createMock(DayLockRepositoryInterface::class);
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $resourceRepo = $this->createMock(ResourceRepositoryInterface::class);
        $availSvc = $this->createMock(AvailabilityServiceInterface::class);
        $settings = $this->makeSettings();
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnCallback(fn($q) => $q);
        $wpdb->method('query')->willReturn(true);

        $service = new BookingService($bookingRepo, $itemRepo, $dayLock, $priceRepo, $resourceRepo, $availSvc, new PricingService($priceRepo), $settings, $wpdb);
        $service->transition(1, BookingStatus::AWAITING_CONFIRMATION);
    }

    public function testTransitionForbiddenThrows(): void
    {
        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        // confirmed cannot go to pending_payment
        $bookingRepo->method('findById')->willReturn($this->makeBookingRow(1, 'confirmed'));

        $service = $this->buildService($bookingRepo);

        $this->expectException(\InvalidArgumentException::class);
        $service->transition(1, BookingStatus::PENDING_PAYMENT);
    }

    public function testTransitionNonExistentBookingThrows(): void
    {
        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        $bookingRepo->method('findById')->willReturn(null);

        $service = $this->buildService($bookingRepo);

        $this->expectException(\RuntimeException::class);
        $service->transition(99, BookingStatus::AWAITING_CONFIRMATION);
    }

    public function testTransitionToCancelledReleasesSlot(): void
    {
        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        $bookingRepo->method('findById')->willReturn($this->makeBookingRow(1, 'pending_payment'));
        $bookingRepo->method('update');

        $itemRepo = $this->createMock(BookingItemRepositoryInterface::class);
        $itemRepo->expects($this->once())->method('releaseByBookingId')->with(1);

        $dayLock  = $this->createMock(DayLockRepositoryInterface::class);
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $resourceRepo = $this->createMock(ResourceRepositoryInterface::class);
        $availSvc = $this->createMock(AvailabilityServiceInterface::class);
        $settings = $this->makeSettings();
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnCallback(fn($q) => $q);
        $wpdb->method('query')->willReturn(true);

        $service = new BookingService($bookingRepo, $itemRepo, $dayLock, $priceRepo, $resourceRepo, $availSvc, new PricingService($priceRepo), $settings, $wpdb);
        $service->transition(1, BookingStatus::CANCELLED);
    }

    public function testTransitionToAwaitingConfirmationIncrementsCodeUsage(): void
    {
        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        $bookingRepo->method('findById')->willReturn($this->makeBookingRow(1, 'pending_payment', 'HOSTE2026'));
        $bookingRepo->expects($this->once())->method('incrementAccessCodeUsage')->with('HOSTE2026');
        $bookingRepo->method('update');

        $itemRepo = $this->createMock(BookingItemRepositoryInterface::class);
        $dayLock  = $this->createMock(DayLockRepositoryInterface::class);
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $resourceRepo = $this->createMock(ResourceRepositoryInterface::class);
        $availSvc = $this->createMock(AvailabilityServiceInterface::class);
        $settings = $this->makeSettings();
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnCallback(fn($q) => $q);
        $wpdb->method('query')->willReturn(true);

        $service = new BookingService($bookingRepo, $itemRepo, $dayLock, $priceRepo, $resourceRepo, $availSvc, new PricingService($priceRepo), $settings, $wpdb);
        $service->transition(1, BookingStatus::AWAITING_CONFIRMATION);
    }

    public function testInvalidComboKeyReturnsFailure(): void
    {
        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $priceRepo->method('findTierBySlug')->willReturn($this->makePriceTier());

        $service = $this->buildService($bookingRepo, priceRepo: $priceRepo);

        $req = new BookingRequest(
            bookingDate: '2026-12-01', slotFrom: '16:00', comboKey: 'jacuzzi',
            customerEmail: 't@t.cz', customerPhone: '+420111222333',
            customerName: null, customerNote: null, guests: null,
            paymentMethod: 'stripe_card', tierSlug: 'public', validCode: null,
            source: 'web', locale: 'cs_CZ', consentIpBin: null,
        );

        $result = $service->create($req);
        $this->assertFalse($result->success);
        $this->assertSame('invalid_combo', $result->errorCode);
    }

    public function testExpireHoldsCallsTransitionForEachExpired(): void
    {
        $expired1 = $this->makeBookingRow(1, 'pending_payment');
        $expired2 = $this->makeBookingRow(2, 'pending_payment');

        $bookingRepo = $this->createMock(BookingRepositoryInterface::class);
        $bookingRepo->method('findExpiredHolds')->willReturn([$expired1, $expired2]);
        $bookingRepo->method('findById')
            ->willReturnCallback(fn($id) => $id === 1 ? $expired1 : $expired2);
        $bookingRepo->expects($this->exactly(2))->method('update');

        $itemRepo = $this->createMock(BookingItemRepositoryInterface::class);
        $itemRepo->expects($this->exactly(2))->method('releaseByBookingId');

        $dayLock  = $this->createMock(DayLockRepositoryInterface::class);
        $priceRepo = $this->createMock(PriceRepositoryInterface::class);
        $resourceRepo = $this->createMock(ResourceRepositoryInterface::class);
        $availSvc = $this->createMock(AvailabilityServiceInterface::class);
        $settings = $this->makeSettings();
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnCallback(fn($q) => $q);
        $wpdb->method('query')->willReturn(true);

        $service = new BookingService($bookingRepo, $itemRepo, $dayLock, $priceRepo, $resourceRepo, $availSvc, new PricingService($priceRepo), $settings, $wpdb);
        $count = $service->expireHolds();

        $this->assertSame(2, $count);
    }
}

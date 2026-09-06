<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Accommodation\AccommodationClassifier;
use Duj\Wellness\Accommodation\AccommodationSyncService;
use Duj\Wellness\Accommodation\IcsParser;
use Duj\Wellness\Repository\AccommodationRepositoryInterface;
use Duj\Wellness\Repository\AccommodationBlock;
use PHPUnit\Framework\TestCase;

final class AccommodationSyncServiceTest extends TestCase
{
    private AccommodationSyncService $svc;

    protected function setUp(): void
    {
        // Concrete implementations — no WP calls needed
        $this->svc = new AccommodationSyncService(
            $this->createMock(AccommodationRepositoryInterface::class),
            new IcsParser(),
            new AccommodationClassifier(),
        );
    }

    public function testIsDataFreshReturnsTrueForRecentSyncedAt(): void
    {
        $recent = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->assertTrue($this->svc->isDataFresh($recent));
    }

    public function testIsDataFreshReturnsFalseForOldSyncedAt(): void
    {
        $old = new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse($this->svc->isDataFresh($old));
    }

    public function testIsDataFreshReturnsFalseForNull(): void
    {
        $this->assertFalse($this->svc->isDataFresh(null));
    }

    public function testGetPolicyForDateReturnsClosedWhenStale(): void
    {
        $old = new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC'));
        $policy = $this->svc->getPolicyForDate('2025-06-01', $old, 'guests_only');
        $this->assertSame('closed', $policy);
    }

    public function testGetPolicyForDateReturnsClosedWhenNullSyncedAt(): void
    {
        $policy = $this->svc->getPolicyForDate('2025-06-01', null, 'guests_only');
        $this->assertSame('closed', $policy);
    }

    public function testGetPolicyForDateReturnsStoredPolicyWhenFresh(): void
    {
        $fresh = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $policy = $this->svc->getPolicyForDate('2025-06-01', $fresh, 'guests_only');
        $this->assertSame('guests_only', $policy);
    }

    public function testGetPolicyForDateReturnsGuestsOnlyWhenFreshButNullPolicy(): void
    {
        $fresh = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $policy = $this->svc->getPolicyForDate('2025-06-01', $fresh, null);
        $this->assertSame('guests_only', $policy);
    }

    public function testSyncWithoutConstantReturnsZero(): void
    {
        // DUJ_ACCOMMODATION_ICS_URL is not defined in test env — sync should return 0
        $repo = $this->createMock(AccommodationRepositoryInterface::class);
        $repo->expects($this->never())->method('upsertFromSync');

        $svc = new AccommodationSyncService($repo, new IcsParser(), new AccommodationClassifier());
        $result = $svc->sync();

        $this->assertSame(0, $result);
    }

    public function testAccommodationClassifierReturnsguestsOnlyByDefault(): void
    {
        $classifier = new AccommodationClassifier();
        $this->assertSame('guests_only', $classifier->classify('Booking', 'Happy guests'));
    }

    public function testAccommodationClassifierReturnsClosedForKeywords(): void
    {
        $classifier = new AccommodationClassifier();
        $this->assertSame('closed', $classifier->classify('Closed for maintenance', ''));
        $this->assertSame('closed', $classifier->classify('Údržba domeček', ''));
        $this->assertSame('closed', $classifier->classify('', 'zavřeno'));
        $this->assertSame('closed', $classifier->classify('Oprava kotlu', ''));
        $this->assertSame('closed', $classifier->classify('Servis', ''));
    }
}

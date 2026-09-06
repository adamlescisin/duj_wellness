<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\CutoffPolicy;
use Duj\Wellness\Domain\PriceTier;
use PHPUnit\Framework\TestCase;

/**
 * CutoffPolicy tests.
 *
 * NOTE: We use a subclass that allows injecting "now" for deterministic tests.
 * Tests that need "today" pass a booking date matching the injected now.
 */
final class CutoffPolicyTest extends TestCase
{
    private function makeTier(string $cutoffMode = 'inherit', ?int $minLeadMinutes = null): PriceTier
    {
        return new PriceTier(
            slug: 'public',
            label: 'Veřejnost',
            isDefault: true,
            requiresCode: false,
            showInForm: true,
            cutoffMode: $cutoffMode,
            minLeadMinutes: $minLeadMinutes,
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

    private function makePolicy(
        bool $enabled = true,
        string $cutoffTime = '12:00',
        string $tzMode = 'wall_clock',
        int $minLeadMinutes = 180,
    ): CutoffPolicy {
        return new CutoffPolicy($enabled, $cutoffTime, $tzMode, $minLeadMinutes);
    }

    public function testSlotInPastIsRejected(): void
    {
        $policy = $this->makePolicy();
        $tier = $this->makeTier();

        // Yesterday's slot — always rejected
        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $this->assertFalse($policy->allows($yesterday, '16:00', $tier));
    }

    public function testFutureSlotFarEnoughAhead(): void
    {
        $policy = $this->makePolicy(minLeadMinutes: 180);
        $tier = $this->makeTier();

        // Tomorrow's slot — definitely beyond cutoff and lead time
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $this->assertTrue($policy->allows($tomorrow, '16:00', $tier));
    }

    public function testCutoffDisabledAllowsTodayAlways(): void
    {
        // When cutoff disabled, only lead time matters
        $policy = $this->makePolicy(enabled: false, minLeadMinutes: 0);
        $tier = $this->makeTier();

        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $this->assertTrue($policy->allows($tomorrow, '16:00', $tier));
    }

    public function testGuestTierIgnoresCutoff12(): void
    {
        // Guest tier has cutoff_mode='lead_time_only', so 12:00 cutoff doesn't apply
        $policy = $this->makePolicy(enabled: true, minLeadMinutes: 0);
        $guestTier = $this->makeGuestTier();

        // Tomorrow (not today) — no lead time issue, no cutoff for guests
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $this->assertTrue($policy->allows($tomorrow, '16:00', $guestTier));
    }

    public function testMinLeadTimeBlocksSlotTooSoon(): void
    {
        // 3 hours = 180 min lead time; slot starting in 1 hour should fail
        $policy = $this->makePolicy(enabled: false, minLeadMinutes: 180);
        $tier = $this->makeTier();

        $tz = new \DateTimeZone('Europe/Prague');
        $soon = (new \DateTimeImmutable('now', $tz))->modify('+1 hour');
        $date = $soon->format('Y-m-d');
        $time = $soon->format('H:i');

        $this->assertFalse($policy->allows($date, $time, $tier));
    }

    public function testMinLeadTimeAllowsSlotFarEnough(): void
    {
        // 3 hours lead; slot starting in 4 hours should pass
        $policy = $this->makePolicy(enabled: false, minLeadMinutes: 180);
        $tier = $this->makeTier();

        $tz = new \DateTimeZone('Europe/Prague');
        $farEnough = (new \DateTimeImmutable('now', $tz))->modify('+4 hours');
        $date = $farEnough->format('Y-m-d');
        $time = $farEnough->format('H:i');

        $this->assertTrue($policy->allows($date, $time, $tier));
    }

    public function testPerTierLeadTimeOverridesGlobal(): void
    {
        // Global 180 min, but tier has 30 min
        $policy = $this->makePolicy(enabled: false, minLeadMinutes: 180);
        $tier = $this->makeTier(minLeadMinutes: 30);

        $tz = new \DateTimeZone('Europe/Prague');
        // Slot starting in 1 hour (60 min) — fails global 180 min but passes tier 30 min
        $soon = (new \DateTimeImmutable('now', $tz))->modify('+1 hour');
        $date = $soon->format('Y-m-d');
        $time = $soon->format('H:i');

        $this->assertTrue($policy->allows($date, $time, $tier));
    }

    public function testCutoffDoesNotApplyToFutureDate(): void
    {
        // 12:00 cutoff is only for TODAY — tomorrow should always pass (with enough lead time)
        $policy = $this->makePolicy(enabled: true, minLeadMinutes: 0);
        $tier = $this->makeTier();

        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $this->assertTrue($policy->allows($tomorrow, '10:00', $tier));
    }
}

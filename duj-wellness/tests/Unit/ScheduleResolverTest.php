<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\ScheduleResolver;
use Duj\Wellness\Repository\AccommodationBlock;
use Duj\Wellness\Repository\AccommodationRepositoryInterface;
use Duj\Wellness\Repository\ScheduleOverride;
use Duj\Wellness\Repository\ScheduleRepositoryInterface;
use Duj\Wellness\Repository\ScheduleRule;
use PHPUnit\Framework\TestCase;

final class ScheduleResolverTest extends TestCase
{
    private function makeSchedRepo(
        ?ScheduleOverride $override = null,
        array $rules = [],
    ): ScheduleRepositoryInterface {
        $repo = $this->createMock(ScheduleRepositoryInterface::class);
        $repo->method('findOverrideForDate')->willReturn($override);
        $repo->method('findRulesForWeekday')->willReturn($rules);
        return $repo;
    }

    private function makeAccomRepo(?AccommodationBlock $block = null): AccommodationRepositoryInterface
    {
        $repo = $this->createMock(AccommodationRepositoryInterface::class);
        $repo->method('findBlockForDate')->willReturn($block);
        return $repo;
    }

    private function makeBlock(string $policy): AccommodationBlock
    {
        return new AccommodationBlock(
            blockDate: '2025-01-01',
            policy: $policy,
            source: 'ical',
            isManual: false,
            synced_at: null,
        );
    }

    private function makeOverride(string $mode, ?array $slots = null): ScheduleOverride
    {
        return new ScheduleOverride(
            id: 1,
            overrideDate: '2025-01-01',
            mode: $mode,
            slots: $slots,
            note: null,
        );
    }

    private function makeRule(string $from = '16:00', string $to = '18:00'): ScheduleRule
    {
        return new ScheduleRule(
            id: 1,
            label: null,
            weekday: 1,
            timeFrom: $from,
            timeTo: $to,
            validFrom: null,
            validTo: null,
            resourceScope: null,
            isActive: true,
        );
    }

    public function testClosedOverrideReturnsNoSlots(): void
    {
        $override = $this->makeOverride('closed');
        $resolver = new ScheduleResolver($this->makeSchedRepo($override), $this->makeAccomRepo());

        $this->assertSame([], $resolver->resolveSlots('2025-01-01'));
    }

    public function testOpenOverrideReturnsSlotsFromOverride(): void
    {
        $override = $this->makeOverride('replace', [['from' => '10:00', 'to' => '12:00']]);
        $resolver = new ScheduleResolver($this->makeSchedRepo($override), $this->makeAccomRepo());

        $slots = $resolver->resolveSlots('2025-01-01');
        $this->assertCount(1, $slots);
        $this->assertSame('10:00', $slots[0]->from);
    }

    public function testAccommodationClosedPolicyReturnsNoSlots(): void
    {
        $resolver = new ScheduleResolver($this->makeSchedRepo(), $this->makeAccomRepo($this->makeBlock('closed')));

        $this->assertSame([], $resolver->resolveSlots('2025-01-01'));
    }

    public function testNoOverrideNoBlockFallsToRules(): void
    {
        $rule = $this->makeRule();
        $resolver = new ScheduleResolver($this->makeSchedRepo(rules: [$rule]), $this->makeAccomRepo());

        $slots = $resolver->resolveSlots('2025-01-06'); // Monday
        $this->assertCount(1, $slots);
        $this->assertSame('16:00', $slots[0]->from);
    }

    public function testResolveDayPolicyReturnsIgnoreWhenNoBlock(): void
    {
        $resolver = new ScheduleResolver($this->makeSchedRepo(), $this->makeAccomRepo());
        $this->assertSame('ignore', $resolver->resolveDayPolicy('2025-01-01'));
    }

    public function testResolveDayPolicyReturnsBlockPolicy(): void
    {
        $resolver = new ScheduleResolver($this->makeSchedRepo(), $this->makeAccomRepo($this->makeBlock('guests_only')));
        $this->assertSame('guests_only', $resolver->resolveDayPolicy('2025-01-01'));
    }

    public function testIgnoreBlockPolicyStillResolvesSlots(): void
    {
        $rule = $this->makeRule();
        $resolver = new ScheduleResolver(
            $this->makeSchedRepo(rules: [$rule]),
            $this->makeAccomRepo($this->makeBlock('ignore')),
        );

        $slots = $resolver->resolveSlots('2025-01-06');
        $this->assertCount(1, $slots);
    }
}

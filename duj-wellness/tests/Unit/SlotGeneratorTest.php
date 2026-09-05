<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\SlotGenerator;
use PHPUnit\Framework\TestCase;

final class SlotGeneratorTest extends TestCase
{
    private SlotGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new SlotGenerator();
    }

    public function testStandardWindow(): void
    {
        // 16:00-21:00 / 120 / 60 → [16:00-18:00, 19:00-21:00]
        $slots = $this->gen->generate('16:00', '21:00', 120, 60);

        $this->assertCount(2, $slots);
        $this->assertSame('16:00', $slots[0]->from);
        $this->assertSame('18:00', $slots[0]->to);
        $this->assertSame('19:00', $slots[1]->from);
        $this->assertSame('21:00', $slots[1]->to);
    }

    public function testNoBuffer(): void
    {
        // 16:00-20:00 / 60 / 0 → [16:00-17:00, 17:00-18:00, 18:00-19:00, 19:00-20:00]
        $slots = $this->gen->generate('16:00', '20:00', 60, 0);
        $this->assertCount(4, $slots);
    }

    public function testSlotDoesNotFit(): void
    {
        // Window = 120 min, slot = 121 min → no slots
        $slots = $this->gen->generate('16:00', '18:00', 121, 0);
        $this->assertCount(0, $slots);
    }

    public function testExactFit(): void
    {
        // Window = 120 min, slot = 120 min → 1 slot
        $slots = $this->gen->generate('16:00', '18:00', 120, 0);
        $this->assertCount(1, $slots);
        $this->assertSame('16:00', $slots[0]->from);
        $this->assertSame('18:00', $slots[0]->to);
    }

    public function testWindowFromEqualsTo(): void
    {
        $slots = $this->gen->generate('16:00', '16:00', 120, 60);
        $this->assertCount(0, $slots);
    }

    public function testInvalidSlotMinutesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->generate('16:00', '21:00', 0, 60);
    }

    public function testSingleSlotFromBufferPushesOutOfWindow(): void
    {
        // 16:00-20:00 / 120 / 60: first slot 16:00-18:00, next starts 19:00, ends 21:00 > 20:00 → only 1 slot
        $slots = $this->gen->generate('16:00', '20:00', 120, 60);
        $this->assertCount(1, $slots);
        $this->assertSame('16:00', $slots[0]->from);
        $this->assertSame('18:00', $slots[0]->to);
    }
}

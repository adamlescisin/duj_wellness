<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\BookingStatus;
use PHPUnit\Framework\TestCase;

final class BookingStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $values = array_map(fn($c) => $c->value, BookingStatus::cases());

        $this->assertContains('pending_payment', $values);
        $this->assertContains('awaiting_confirmation', $values);
        $this->assertContains('confirmed', $values);
        $this->assertContains('rejected', $values);
        $this->assertContains('cancelled', $values);
        $this->assertContains('no_show', $values);
    }

    public function testBlockingStatuses(): void
    {
        $this->assertTrue(BookingStatus::PENDING_PAYMENT->isBlocking());
        $this->assertTrue(BookingStatus::AWAITING_CONFIRMATION->isBlocking());
        $this->assertTrue(BookingStatus::CONFIRMED->isBlocking());
        $this->assertTrue(BookingStatus::NO_SHOW->isBlocking());
    }

    public function testNonBlockingStatuses(): void
    {
        $this->assertFalse(BookingStatus::REJECTED->isBlocking());
        $this->assertFalse(BookingStatus::CANCELLED->isBlocking());
        $this->assertFalse(BookingStatus::EXPIRED->isBlocking());
    }

    public function testPendingPaymentCanTransitionToAwaitingConfirmation(): void
    {
        $allowed = BookingStatus::PENDING_PAYMENT->allowedTransitions();
        $this->assertContains(BookingStatus::AWAITING_CONFIRMATION, $allowed);
    }

    public function testPendingPaymentCanTransitionToCancelled(): void
    {
        $allowed = BookingStatus::PENDING_PAYMENT->allowedTransitions();
        $this->assertContains(BookingStatus::CANCELLED, $allowed);
    }

    public function testAwaitingConfirmationCanTransitionToConfirmed(): void
    {
        $allowed = BookingStatus::AWAITING_CONFIRMATION->allowedTransitions();
        $this->assertContains(BookingStatus::CONFIRMED, $allowed);
    }

    public function testAwaitingConfirmationCanTransitionToRejected(): void
    {
        $allowed = BookingStatus::AWAITING_CONFIRMATION->allowedTransitions();
        $this->assertContains(BookingStatus::REJECTED, $allowed);
    }

    public function testConfirmedCanTransitionToNoShow(): void
    {
        $allowed = BookingStatus::CONFIRMED->allowedTransitions();
        $this->assertContains(BookingStatus::NO_SHOW, $allowed);
    }

    public function testConfirmedCanTransitionToCancelled(): void
    {
        $allowed = BookingStatus::CONFIRMED->allowedTransitions();
        $this->assertContains(BookingStatus::CANCELLED, $allowed);
    }

    public function testRejectedHasNoTransitions(): void
    {
        $this->assertEmpty(BookingStatus::REJECTED->allowedTransitions());
    }

    public function testExpiredHasNoTransitions(): void
    {
        $this->assertEmpty(BookingStatus::EXPIRED->allowedTransitions());
    }

    public function testCancelledHasNoTransitions(): void
    {
        $this->assertEmpty(BookingStatus::CANCELLED->allowedTransitions());
    }

    public function testCanTransitionTo(): void
    {
        $this->assertTrue(BookingStatus::PENDING_PAYMENT->canTransitionTo(BookingStatus::AWAITING_CONFIRMATION));
        $this->assertFalse(BookingStatus::PENDING_PAYMENT->canTransitionTo(BookingStatus::CONFIRMED));
    }

    public function testFromValue(): void
    {
        $status = BookingStatus::from('confirmed');
        $this->assertSame(BookingStatus::CONFIRMED, $status);
    }
}

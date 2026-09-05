<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Domain\BookingServiceInterface;
use Duj\Wellness\Domain\BookingStatus;
use Duj\Wellness\Payment\StripeGatewayInterface;
use Duj\Wellness\Payment\StripeWebhookHandler;
use Duj\Wellness\Repository\BookingRepositoryInterface;
use Duj\Wellness\Repository\BookingRow;
use PHPUnit\Framework\TestCase;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;

final class StripeWebhookHandlerTest extends TestCase
{
    private function makeBookingRow(int $id = 1, string $status = 'pending_payment'): BookingRow
    {
        return new BookingRow(
            id:              $id,
            uuid:            'test-uuid-1234',
            reference:       'W20250101ABC123',
            bookingDate:     '2025-06-01',
            slotFrom:        '10:00',
            slotTo:          '12:00',
            comboKey:        'sauna+sud',
            guests:          2,
            status:          $status,
            tierSlug:        'public',
            accessCode:      null,
            amountMinor:     200000,
            currency:        'CZK',
            customerName:    'Test User',
            customerEmail:   'test@example.com',
            customerPhone:   '+420777000000',
            customerNote:    null,
            adminNote:       null,
            paymentMethod:   'stripe_card',
            paymentStatus:   'pending',
            paymentProvider: 'stripe',
            paymentIntentId: 'pi_test123',
            paymentMeta:     null,
            holdExpiresAt:   '2025-06-01 10:30:00',
            authExpiresAt:   null,
            confirmedAt:     null,
            confirmedBy:     null,
            cancelledAt:     null,
            cancelReason:    null,
            consentAt:       null,
            source:          'web',
            locale:          'cs_CZ',
            createdAt:       '2025-06-01 09:00:00',
            updatedAt:       '2025-06-01 09:00:00',
        );
    }

    private function makeEvent(string $type, object $dataObject): Event
    {
        return Event::constructFrom([
            'id'       => 'evt_test_' . uniqid(),
            'type'     => $type,
            'object'   => 'event',
            'data'     => ['object' => $dataObject],
            'livemode' => false,
            'created'  => time(),
        ]);
    }

    private function makeHandler(
        StripeGatewayInterface $gateway,
        BookingRepositoryInterface $repo,
        BookingServiceInterface $svc,
    ): StripeWebhookHandler {
        return new StripeWebhookHandler($gateway, $repo, $svc);
    }

    public function testInvalidSignatureReturns400(): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')
            ->willThrowException(new SignatureVerificationException('bad sig'));

        $repo    = $this->createMock(BookingRepositoryInterface::class);
        $svc     = $this->createMock(BookingServiceInterface::class);
        $handler = $this->makeHandler($gateway, $repo, $svc);

        $result = $handler->handle('{}', 'bad-sig', 'whsec_test');

        $this->assertSame(400, $result['status']);
    }

    public function testValidEventReturns200(): void
    {
        $intent = StripeObject::constructFrom(['id' => 'pi_unknown', 'object' => 'payment_intent']);
        $event  = $this->makeEvent('some.unknown.event', $intent);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo    = $this->createMock(BookingRepositoryInterface::class);
        $svc     = $this->createMock(BookingServiceInterface::class);
        $handler = $this->makeHandler($gateway, $repo, $svc);

        $result = $handler->handle('{}', 'sig', 'whsec_test');

        $this->assertSame(200, $result['status']);
    }

    public function testAmountCapturableUpdatedTransitionsToAwaitingConfirmation(): void
    {
        $booking = $this->makeBookingRow(status: 'pending_payment');
        $intent  = StripeObject::constructFrom(['id' => 'pi_test123', 'object' => 'payment_intent']);
        $event   = $this->makeEvent('payment_intent.amount_capturable_updated', $intent);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findByPaymentIntentId')->with('pi_test123')->willReturn($booking);

        $svc = $this->createMock(BookingServiceInterface::class);
        $svc->expects($this->once())
            ->method('transition')
            ->with(
                $this->equalTo(1),
                $this->equalTo(BookingStatus::AWAITING_CONFIRMATION),
                $this->arrayHasKey('auth_expires_at')
            );

        $this->makeHandler($gateway, $repo, $svc)->handle('{}', 'sig', 'whsec');
    }

    public function testPaymentIntentCanceledTransitionsToExpired(): void
    {
        $booking = $this->makeBookingRow(status: 'pending_payment');
        $intent  = StripeObject::constructFrom(['id' => 'pi_test123', 'object' => 'payment_intent']);
        $event   = $this->makeEvent('payment_intent.canceled', $intent);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findByPaymentIntentId')->with('pi_test123')->willReturn($booking);

        $svc = $this->createMock(BookingServiceInterface::class);
        $svc->expects($this->once())
            ->method('transition')
            ->with($this->equalTo(1), $this->equalTo(BookingStatus::EXPIRED));

        $this->makeHandler($gateway, $repo, $svc)->handle('{}', 'sig', 'whsec');
    }

    public function testPaymentIntentFailedUpdatesPaymentStatus(): void
    {
        $booking = $this->makeBookingRow(status: 'pending_payment');
        $intent  = StripeObject::constructFrom(['id' => 'pi_test123', 'object' => 'payment_intent']);
        $event   = $this->makeEvent('payment_intent.payment_failed', $intent);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findByPaymentIntentId')->with('pi_test123')->willReturn($booking);
        $repo->expects($this->once())
            ->method('update')
            ->with(
                1,
                $this->callback(fn($d) => ($d['payment_status'] ?? '') === 'failed')
            );

        $svc = $this->createMock(BookingServiceInterface::class);

        $this->makeHandler($gateway, $repo, $svc)->handle('{}', 'sig', 'whsec');
    }

    public function testChargeRefundedUpdatesPaymentStatus(): void
    {
        $booking = $this->makeBookingRow(status: 'confirmed');
        $charge  = StripeObject::constructFrom([
            'id'             => 'ch_test123',
            'object'         => 'charge',
            'payment_intent' => 'pi_test123',
        ]);
        $event = $this->makeEvent('charge.refunded', $charge);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findByPaymentIntentId')->with('pi_test123')->willReturn($booking);
        $repo->expects($this->once())
            ->method('update')
            ->with(
                1,
                $this->callback(fn($d) => ($d['payment_status'] ?? '') === 'refunded')
            );

        $svc = $this->createMock(BookingServiceInterface::class);

        $this->makeHandler($gateway, $repo, $svc)->handle('{}', 'sig', 'whsec');
    }

    public function testDispatchIgnoresUnknownEventType(): void
    {
        $obj   = StripeObject::constructFrom(['id' => 'obj_1', 'object' => 'unknown']);
        $event = $this->makeEvent('some.unknown.event', $obj);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->expects($this->never())->method('update');

        $svc = $this->createMock(BookingServiceInterface::class);
        $svc->expects($this->never())->method('transition');

        $result = $this->makeHandler($gateway, $repo, $svc)->handle('{}', 'sig', 'whsec');

        $this->assertSame(200, $result['status']);
    }

    public function testInvalidTransitionIsIgnoredGracefully(): void
    {
        // Pokud je přechod nedovolený (InvalidArgumentException), webhook nesmí selhat
        $booking = $this->makeBookingRow(status: 'confirmed'); // nespojitelný s awaiting_confirmation
        $intent  = StripeObject::constructFrom(['id' => 'pi_test123', 'object' => 'payment_intent']);
        $event   = $this->makeEvent('payment_intent.amount_capturable_updated', $intent);

        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->method('constructWebhookEvent')->willReturn($event);

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findByPaymentIntentId')->willReturn($booking);

        $svc = $this->createMock(BookingServiceInterface::class);
        $svc->method('transition')->willThrowException(new \InvalidArgumentException('bad transition'));

        $result = $this->makeHandler($gateway, $repo, $svc)->handle('{}', 'sig', 'whsec');

        // Musíme dostat 200 i při chybě přechodu
        $this->assertSame(200, $result['status']);
    }
}

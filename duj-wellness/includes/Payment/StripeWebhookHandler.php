<?php

declare(strict_types=1);

namespace Duj\Wellness\Payment;

use Duj\Wellness\Domain\BookingServiceInterface;
use Duj\Wellness\Domain\BookingStatus;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Repository\BookingRepositoryInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Event;

/**
 * Zpracuje Stripe webhook event.
 *
 * BEZPEČNOSTNÍ PRAVIDLA:
 * - Ověřuje Stripe-Signature před veškerou logikou.
 * - Idempotence: event.id ukládáme do transientu (24 h) — duplicitní event je ignorován.
 * - Nikdy nevracíme 500 kvůli chybě v notifikaci — notifikace jdou asynchronně.
 * - Secret key se nezaloguje.
 *
 * Zpracované eventy:
 * - payment_intent.amount_capturable_updated → awaiting_confirmation
 * - payment_intent.succeeded                 → payment_status = paid
 * - payment_intent.payment_failed            → payment_status = failed
 * - payment_intent.canceled                  → expired/rejected + uvolní slot
 * - charge.refunded                          → payment_status = refunded
 * - checkout.session.completed               → pro qr_checkout
 */
final class StripeWebhookHandler
{
    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly BookingRepositoryInterface $bookingRepo,
        private readonly BookingServiceInterface $bookingService,
        private readonly ?NotificationService $notificationService = null,
    ) {}

    /**
     * Zpracuje raw payload z Stripe.
     *
     * @return array{status: int, body: array}
     */
    public function handle(string $payload, string $sigHeader, string $webhookSecret): array
    {
        // 1. Ověř podpis
        try {
            $event = $this->gateway->constructWebhookEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            error_log('[duj-wellness] Stripe webhook: neplatný podpis.');
            return ['status' => 400, 'body' => ['error' => 'Invalid signature']];
        } catch (\Throwable $e) {
            error_log('[duj-wellness] Stripe webhook: chyba parsování.');
            return ['status' => 400, 'body' => ['error' => 'Parse error']];
        }

        // 2. Idempotence — ignoruj duplicitní eventy
        $transientKey = 'duj_stripe_event_' . $event->id;
        if (function_exists('get_transient') && get_transient($transientKey) !== false) {
            return ['status' => 200, 'body' => ['ok' => true, 'duplicate' => true]];
        }
        if (function_exists('set_transient')) {
            set_transient($transientKey, 1, DAY_IN_SECONDS);
        }

        // 3. Dispatch dle typu eventu
        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            // Nikdy nesmíme vrátit 500 — Stripe by event opakoval do nekonečna
            error_log('[duj-wellness] Stripe webhook dispatch chyba: ' . $e->getMessage());
        }

        return ['status' => 200, 'body' => ['ok' => true]];
    }

    private function dispatch(Event $event): void
    {
        match ($event->type) {
            'payment_intent.amount_capturable_updated' => $this->onAmountCapturableUpdated($event),
            'payment_intent.succeeded'                 => $this->onPaymentIntentSucceeded($event),
            'payment_intent.payment_failed'            => $this->onPaymentIntentFailed($event),
            'payment_intent.canceled'                  => $this->onPaymentIntentCanceled($event),
            'charge.refunded'                          => $this->onChargeRefunded($event),
            'checkout.session.completed'               => $this->onCheckoutSessionCompleted($event),
            default                                    => null, // ignoruj ostatní eventy
        };
    }

    /** Karta autorizována → přejdi do awaiting_confirmation. */
    private function onAmountCapturableUpdated(Event $event): void
    {
        $intent = $event->data->object;
        $booking = $this->findBookingByIntent($intent->id ?? '');
        if ($booking === null) {
            return;
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $authExpiresAt = $nowUtc->modify('+7 days')->format('Y-m-d H:i:s');

        try {
            $this->bookingService->transition($booking->id, BookingStatus::AWAITING_CONFIRMATION, [
                'auth_expires_at'  => $authExpiresAt,
                'payment_provider' => 'stripe',
                'payment_status'   => 'authorized',
            ]);

            if ($this->notificationService !== null) {
                $fresh = $this->bookingRepo->findById($booking->id);
                if ($fresh !== null) {
                    $this->notificationService->sendAwaitingConfirmation($fresh);
                }
            }
        } catch (\InvalidArgumentException) {
            // Přechod není povolen (např. booking už není pending_payment) — ignoruj
        }
    }

    /** PaymentIntent succeeded (capture proběhl nebo automatic capture). */
    private function onPaymentIntentSucceeded(Event $event): void
    {
        $intent  = $event->data->object;
        $booking = $this->findBookingByIntent($intent->id ?? '');
        if ($booking === null) {
            return;
        }

        $this->bookingRepo->update($booking->id, [
            'payment_status' => 'paid',
            'updated_at'     => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /** Platba selhala — ponechme hold do expirace. */
    private function onPaymentIntentFailed(Event $event): void
    {
        $intent  = $event->data->object;
        $booking = $this->findBookingByIntent($intent->id ?? '');
        if ($booking === null) {
            return;
        }

        $this->bookingRepo->update($booking->id, [
            'payment_status' => 'failed',
            'updated_at'     => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /** PaymentIntent canceled → uvolni slot (expired). */
    private function onPaymentIntentCanceled(Event $event): void
    {
        $intent  = $event->data->object;
        $booking = $this->findBookingByIntent($intent->id ?? '');
        if ($booking === null) {
            return;
        }

        try {
            $this->bookingService->transition($booking->id, BookingStatus::EXPIRED);
        } catch (\InvalidArgumentException) {
            // Přechod není povolen — ignoruj
        }
    }

    /** Platba vrácena. */
    private function onChargeRefunded(Event $event): void
    {
        $charge   = $event->data->object;
        $intentId = $charge->payment_intent ?? '';
        if ($intentId === '') {
            return;
        }

        $booking = $this->findBookingByIntent($intentId);
        if ($booking === null) {
            return;
        }

        $this->bookingRepo->update($booking->id, [
            'payment_status' => 'refunded',
            'updated_at'     => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /** Checkout Session completed (qr_checkout) — stejné jako amount_capturable_updated. */
    private function onCheckoutSessionCompleted(Event $event): void
    {
        $session = $event->data->object;

        // Pokud session má payment_intent, zpracujeme přes PI logiku
        $intentId = $session->payment_intent ?? '';
        if ($intentId === '') {
            return;
        }

        $booking = $this->findBookingByIntent($intentId);
        if ($booking === null) {
            // Zkus přes metadata booking_uuid
            $uuid = $session->metadata->booking_uuid ?? '';
            if ($uuid === '') {
                return;
            }
            $booking = $this->bookingRepo->findByUuid($uuid);
            if ($booking === null) {
                return;
            }
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $authExpiresAt = $nowUtc->modify('+7 days')->format('Y-m-d H:i:s');

        try {
            $this->bookingService->transition($booking->id, BookingStatus::AWAITING_CONFIRMATION, [
                'auth_expires_at'  => $authExpiresAt,
                'payment_provider' => 'stripe',
                'payment_status'   => 'authorized',
                'payment_intent_id' => $intentId,
            ]);

            if ($this->notificationService !== null) {
                $fresh = $this->bookingRepo->findById($booking->id);
                if ($fresh !== null) {
                    $this->notificationService->sendAwaitingConfirmation($fresh);
                }
            }
        } catch (\InvalidArgumentException) {
            // Přechod není povolen — ignoruj
        }
    }

    private function findBookingByIntent(string $intentId): ?\Duj\Wellness\Repository\BookingRow
    {
        if ($intentId === '') {
            return null;
        }
        return $this->bookingRepo->findByPaymentIntentId($intentId);
    }
}

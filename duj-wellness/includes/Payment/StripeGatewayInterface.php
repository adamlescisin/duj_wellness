<?php

declare(strict_types=1);

namespace Duj\Wellness\Payment;

interface StripeGatewayInterface
{
    /** @return array{intent_id: string, client_secret: string} */
    public function createPaymentIntent(int $amountMinor, string $currency, string $bookingUuid, string $reference): array;

    /** @return array{session_id: string, session_url: string, intent_id: string} */
    public function createCheckoutSession(int $amountMinor, string $currency, string $bookingUuid, string $reference, string $successUrl, string $cancelUrl, string $holdExpiresAt): array;

    public function captureIntent(string $intentId): void;

    public function cancelIntent(string $intentId): void;

    public function refundIntent(string $intentId, ?int $amountMinor = null, ?string $currency = null): void;

    public function constructWebhookEvent(string $payload, string $sigHeader, string $webhookSecret): \Stripe\Event;

    public function toStripeAmount(int $amountMinor, string $currency): int;
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Payment;

use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Obaluje Stripe SDK pro operace s PaymentIntenty a Checkout Sessions.
 *
 * BEZPEČNOSTNÍ PRAVIDLA:
 * - Secret key čteme z konstanty DUJ_STRIPE_SECRET_KEY nebo ze settings (wp_options).
 *   Secret key se NIKDY nezaloguje.
 * - Publishable key (pk_*) se bezpečně předává frontendu.
 * - CZK je zero-decimal pouze z pohledu Stripe — my ukládáme v haléřích,
 *   ale do Stripe API posíláme intdiv(amountMinor, 100) (celé Kč).
 * - idempotency_key = booking_uuid, ať dvojité volání nevytvoří duplicitu.
 */
final class StripeGateway implements StripeGatewayInterface
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    /**
     * Vytvoří PaymentIntent s capture_method: manual (autorizace, ne okamžitý strhnutí).
     *
     * @param  int    $amountMinor  Částka v haléřích (např. 150000 = 1 500 Kč)
     * @param  string $currency     'czk'
     * @param  string $bookingUuid  UUID rezervace (idempotency key + metadata)
     * @param  string $reference    Referenční číslo rezervace (pro metadata)
     * @return array{intent_id: string, client_secret: string}
     * @throws \RuntimeException Pokud Stripe API selže
     */
    public function createPaymentIntent(
        int $amountMinor,
        string $currency,
        string $bookingUuid,
        string $reference,
    ): array {
        // CZK je u Stripe zero-decimal: posíláme celé Kč, ne haléře
        $stripeAmount = $this->toStripeAmount($amountMinor, $currency);

        try {
            $intent = $this->client->paymentIntents->create(
                [
                    'amount'                   => $stripeAmount,
                    'currency'                 => strtolower($currency),
                    'capture_method'           => 'manual',
                    'automatic_payment_methods' => ['enabled' => true],
                    'metadata'                 => [
                        'booking_uuid' => $bookingUuid,
                        'reference'    => $reference,
                    ],
                ],
                ['idempotency_key' => $bookingUuid]
            );

            if (!isset($intent->client_secret)) {
                throw new \RuntimeException('Stripe vrátil PaymentIntent bez client_secret.');
            }

            return [
                'intent_id'     => $intent->id,
                'client_secret' => $intent->client_secret,
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe API chyba: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Vytvoří Stripe Checkout Session pro qr_checkout tok.
     * Session umožní zákazníkovi zaplatit telefonem (Apple/Google Pay).
     *
     * @param  int    $amountMinor    Částka v haléřích
     * @param  string $currency       'czk'
     * @param  string $bookingUuid    UUID rezervace
     * @param  string $reference      Referenční číslo
     * @param  string $successUrl     URL po úspěšné platbě
     * @param  string $cancelUrl      URL po zrušení
     * @param  string $holdExpiresAt  UTC datetime 'Y-m-d H:i:s' (pro expires_after_seconds)
     * @return array{session_id: string, session_url: string, intent_id: string}
     * @throws \RuntimeException Pokud Stripe API selže
     */
    public function createCheckoutSession(
        int $amountMinor,
        string $currency,
        string $bookingUuid,
        string $reference,
        string $successUrl,
        string $cancelUrl,
        string $holdExpiresAt,
    ): array {
        $stripeAmount = $this->toStripeAmount($amountMinor, $currency);

        // Přepočítej hold_expires_at na expires_after_seconds (max 24 h dle Stripe)
        $expiresAt = max(
            time() + 1800, // minimum 30 min
            (new \DateTimeImmutable($holdExpiresAt, new \DateTimeZone('UTC')))->getTimestamp()
        );
        $expiresAfter = min($expiresAt - time(), 86400); // Stripe max: 24 h

        try {
            $session = $this->client->checkout->sessions->create(
                [
                    'mode'                => 'payment',
                    'payment_method_types' => ['card'],
                    'line_items'          => [[
                        'price_data' => [
                            'currency'     => strtolower($currency),
                            'unit_amount'  => $stripeAmount,
                            'product_data' => ['name' => 'Wellness rezervace ' . $reference],
                        ],
                        'quantity' => 1,
                    ]],
                    'payment_intent_data' => [
                        'capture_method' => 'manual',
                        'metadata'       => [
                            'booking_uuid' => $bookingUuid,
                            'reference'    => $reference,
                        ],
                    ],
                    'success_url'          => $successUrl,
                    'cancel_url'           => $cancelUrl,
                    'expires_at'           => time() + $expiresAfter,
                    'metadata'             => [
                        'booking_uuid' => $bookingUuid,
                        'reference'    => $reference,
                    ],
                ],
                ['idempotency_key' => 'qr-' . $bookingUuid]
            );

            if (!isset($session->url)) {
                throw new \RuntimeException('Stripe vrátil Session bez URL.');
            }

            $intentId = '';
            if (isset($session->payment_intent) && is_string($session->payment_intent)) {
                $intentId = $session->payment_intent;
            }

            return [
                'session_id'  => $session->id,
                'session_url' => $session->url,
                'intent_id'   => $intentId,
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe API chyba: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Capture PaymentIntentu — strhne autorizovanou částku.
     *
     * @throws \RuntimeException
     */
    public function captureIntent(string $intentId): void
    {
        try {
            $this->client->paymentIntents->capture($intentId);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe capture selhal: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancel PaymentIntentu — uvolní autorizovanou částku.
     *
     * @throws \RuntimeException
     */
    public function cancelIntent(string $intentId): void
    {
        try {
            $this->client->paymentIntents->cancel($intentId);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe cancel selhal: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Refund PaymentIntentu (vrácení platby).
     *
     * @throws \RuntimeException
     */
    public function refundIntent(string $intentId, ?int $amountMinor = null, ?string $currency = null): void
    {
        $params = ['payment_intent' => $intentId];

        if ($amountMinor !== null && $currency !== null) {
            $params['amount'] = $this->toStripeAmount($amountMinor, $currency);
        }

        try {
            $this->client->refunds->create($params);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe refund selhal: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Ověří Stripe webhook podpis a vrátí dekódovaný Event objekt.
     *
     * @throws \Stripe\Exception\SignatureVerificationException Pokud podpis neplatí
     */
    public function constructWebhookEvent(string $payload, string $sigHeader, string $webhookSecret): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }

    /**
     * CZK je u Stripe "zero-decimal" měna — posíláme celé Kč, ne haléře.
     * Viz: https://stripe.com/docs/currencies#zero-decimal
     * Unit test na to: StripeAmountTest.
     */
    public function toStripeAmount(int $amountMinor, string $currency): int
    {
        return match (strtolower($currency)) {
            'czk'   => intdiv($amountMinor, 100),
            default => $amountMinor, // USD, EUR apod. jsou v centech (minor units)
        };
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\BookingRequest;
use Duj\Wellness\Domain\BookingService;
use Duj\Wellness\Domain\TierResolver;
use Duj\Wellness\Payment\QrPaymentGenerator;
use Duj\Wellness\Payment\StripeGatewayFactory;
use Duj\Wellness\Payment\StripeGatewayInterface;
use Duj\Wellness\Repository\BookingRepositoryInterface;
use Duj\Wellness\Support\Dates;
use Duj\Wellness\Support\RateLimiter;
use Duj\Wellness\Support\SettingsInterface;

final class BookingsController
{
    public const NAMESPACE = 'duj/v1';

    private const ALLOWED_PAYMENT_METHODS = ['stripe_card', 'qr_checkout', 'bank_transfer'];

    public function __construct(
        private readonly BookingService $bookingService,
        private readonly TierResolver $tierResolver,
        private readonly RateLimiter $rateLimiter,
        private readonly ?StripeGatewayInterface $stripeGateway = null,
        private readonly ?SettingsInterface $settings = null,
        private readonly ?BookingRepositoryInterface $bookingRepo = null,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/bookings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create'],
            'permission_callback' => '__return_true',
            'args'                => $this->createArgs(),
        ]);
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = $this->getClientIp($request);

        if (!$this->rateLimiter->check('bookings/create', $ip)) {
            return new \WP_REST_Response(
                ['code' => 'rate_limited', 'message' => __('Příliš mnoho požadavků. Zkuste to znovu za chvíli.', 'duj-wellness')],
                429
            );
        }

        $date          = (string) $request->get_param('booking_date');
        $slotFrom      = (string) $request->get_param('slot_from');
        $comboKey      = (string) $request->get_param('combo_key');
        $email         = (string) $request->get_param('customer_email');
        $phone         = (string) $request->get_param('customer_phone');
        $name          = $request->get_param('customer_name');
        $note          = $request->get_param('customer_note');
        $guests        = $request->get_param('guests');
        $paymentMethod = (string) $request->get_param('payment_method');
        $accessCode    = $request->get_param('code') ?: null;

        if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            return new \WP_REST_Response(
                ['code' => 'invalid_payment_method', 'message' => __('Neplatná platební metoda.', 'duj-wellness')],
                400
            );
        }

        // Rozlišení cenové hladiny z kódu
        $resolution = $this->tierResolver->resolve($accessCode, $date);
        if ($resolution->invalidCode) {
            return new \WP_REST_Response(
                ['code' => 'invalid_code', 'message' => __('Kód neplatí.', 'duj-wellness')],
                400
            );
        }

        // IP pro GDPR consent
        $ipBin = null;
        if ($ip !== '0.0.0.0') {
            $bin = inet_pton($ip);
            $ipBin = $bin !== false ? $bin : null;
        }

        $req = new BookingRequest(
            bookingDate:   $date,
            slotFrom:      $slotFrom,
            comboKey:      $comboKey,
            customerEmail: $email,
            customerPhone: $phone,
            customerName:  is_string($name) ? $name : null,
            customerNote:  is_string($note) ? $note : null,
            guests:        is_numeric($guests) ? (int) $guests : null,
            paymentMethod: $paymentMethod,
            tierSlug:      $resolution->tier->slug,
            validCode:     $resolution->validCode,
            source:        'web',
            locale:        'cs_CZ',
            consentIpBin:  $ipBin,
        );

        $result = $this->bookingService->create($req);

        if (!$result->success) {
            $httpCode = match ($result->errorCode) {
                'slot_taken'            => 409,
                'invalid_combo',
                'invalid_tier',
                'price_not_found',
                'resource_not_found',
                'slot_not_found'        => 400,
                default                 => 500,
            };

            return new \WP_REST_Response(
                ['code' => $result->errorCode, 'message' => $result->errorMessage],
                $httpCode
            );
        }

        $responseData = [
            'booking_id' => $result->bookingId,
            'uuid'       => $result->uuid,
            'reference'  => $result->reference,
            'status'     => 'pending_payment',
        ];

        // Vytvoř Stripe PaymentIntent / Checkout Session
        if (in_array($paymentMethod, ['stripe_card', 'qr_checkout'], true) && $this->stripeGateway !== null) {
            $booking = $this->bookingRepo?->findById($result->bookingId);
            if ($booking !== null) {
                $responseData['payment'] = $this->createStripePayment(
                    $paymentMethod,
                    $booking,
                    $result->uuid,
                    $result->reference,
                );
            }
        }

        // Bankovní převod — nastav prodlouženou dobu rezervace a vrať QR data
        if ($paymentMethod === 'bank_transfer') {
            $responseData['payment'] = $this->createBankTransferPayment($result->bookingId, $result->reference);
        }

        return new \WP_REST_Response($responseData, 201);
    }

    private function createStripePayment(
        string $paymentMethod,
        \Duj\Wellness\Repository\BookingRow $booking,
        string $uuid,
        string $reference,
    ): array {
        $publishableKey = $this->settings !== null
            ? StripeGatewayFactory::resolvePublishableKey($this->settings)
            : '';

        if ($paymentMethod === 'stripe_card') {
            try {
                $pi = $this->stripeGateway->createPaymentIntent(
                    $booking->amountMinor,
                    $booking->currency,
                    $uuid,
                    $reference,
                );

                // Ulož intent_id do DB
                $this->bookingRepo?->update($booking->id, ['payment_intent_id' => $pi['intent_id'], 'payment_provider' => 'stripe']);

                return [
                    'provider'        => 'stripe',
                    'client_secret'   => $pi['client_secret'],
                    'publishable_key' => $publishableKey,
                ];
            } catch (\RuntimeException $e) {
                error_log('[duj-wellness] BookingsController: createPaymentIntent selhal: ' . $e->getMessage());
                return ['provider' => 'stripe', 'error' => 'payment_init_failed'];
            }
        }

        // qr_checkout
        if ($paymentMethod === 'qr_checkout') {
            $siteUrl    = function_exists('get_site_url') ? get_site_url() : 'https://domecekujosefa.cz';
            $successUrl = $siteUrl . '/rezervace/dokonceni/?uuid=' . $uuid;
            $cancelUrl  = $siteUrl . '/rezervace/?cancelled=1';

            try {
                $session = $this->stripeGateway->createCheckoutSession(
                    $booking->amountMinor,
                    $booking->currency,
                    $uuid,
                    $reference,
                    $successUrl,
                    $cancelUrl,
                    $booking->holdExpiresAt ?? (new \DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                );

                // Ulož intent_id pokud je k dispozici
                if ($session['intent_id'] !== '') {
                    $this->bookingRepo?->update($booking->id, [
                        'payment_intent_id' => $session['intent_id'],
                        'payment_provider'  => 'stripe',
                    ]);
                }

                return [
                    'provider'    => 'stripe',
                    'session_url' => $session['session_url'],
                    'session_id'  => $session['session_id'],
                ];
            } catch (\RuntimeException $e) {
                error_log('[duj-wellness] BookingsController: createCheckoutSession selhal: ' . $e->getMessage());
                return ['provider' => 'stripe', 'error' => 'payment_init_failed'];
            }
        }

        return [];
    }

    private function createBankTransferPayment(int $bookingId, string $reference): array
    {
        if ($this->settings === null || $this->bookingRepo === null) {
            return ['provider' => 'bank_transfer'];
        }

        $holdHours  = max(1, (int) $this->settings->get('qr_bank_hold_hours', 48));
        $expiresAt  = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))
            ->modify("+{$holdHours} hours")
            ->format('Y-m-d H:i:s');

        $this->bookingRepo->update($bookingId, [
            'hold_expires_at'  => $expiresAt,
            'payment_provider' => 'bank_transfer',
        ]);

        $iban   = $this->settings->bankAccountIban();
        $number = $this->settings->bankAccountNumber();

        $booking = $this->bookingRepo->findById($bookingId);
        $qrData  = [];
        if ($booking !== null && ($iban !== '' || $number !== '')) {
            $ibanForQr = $iban !== '' ? $iban : '';
            if ($ibanForQr !== '') {
                $spd = (new QrPaymentGenerator())->generate(
                    $ibanForQr,
                    $booking->amountMinor,
                    $reference,
                    'Wellness rezervace ' . $reference,
                );
                $qrData['spd'] = $spd;
            }
        }

        return array_merge([
            'provider'         => 'bank_transfer',
            'iban'             => $iban,
            'account_number'   => $number,
            'variable_symbol'  => preg_replace('/[^0-9]/', '', $reference),
            'hold_expires_at'  => $expiresAt,
            'hold_hours'       => $holdHours,
        ], $qrData);
    }

    private function createArgs(): array
    {
        return [
            'booking_date' => [
                'required'          => true,
                'validate_callback' => fn($v) => Dates::isValidDate((string) $v),
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'slot_from' => [
                'required'          => true,
                'validate_callback' => fn($v) => (bool) preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $v),
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'combo_key' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'customer_email' => [
                'required'          => true,
                'validate_callback' => fn($v) => is_email($v),
                'sanitize_callback' => 'sanitize_email',
            ],
            'customer_phone' => [
                'required'          => true,
                'validate_callback' => fn($v) => strlen(trim((string) $v)) >= 9,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'customer_name' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'customer_note' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'guests' => [
                'required'          => false,
                'validate_callback' => fn($v) => $v === null || (is_numeric($v) && (int) $v > 0),
                'sanitize_callback' => fn($v) => $v !== null ? (int) $v : null,
            ],
            'payment_method' => [
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'code' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    private function getClientIp(\WP_REST_Request $request): string
    {
        $server = $request->get_server_params();

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            if (!empty($server[$header])) {
                $ip = trim(explode(',', $server[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}

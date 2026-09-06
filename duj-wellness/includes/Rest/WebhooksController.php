<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Payment\StripeWebhookHandler;
use Duj\Wellness\Support\SettingsInterface;

/**
 * REST endpoint pro Stripe webhooky.
 * POST /duj/v1/webhooks/stripe — veřejný, ověřovaný podpisem.
 *
 * BEZPEČNOSTNÍ PRAVIDLA:
 * - Webhook secret se čte ze settings/konstanty, nikdy se nezaloguje.
 * - Vrací vždy 200 i při chybě zpracování (Stripe by event jinak opakoval).
 * - GET na tento endpoint neprovádí žádnou akci.
 */
final class WebhooksController
{
    public const NAMESPACE = 'duj/v1';

    public function __construct(
        private readonly StripeWebhookHandler $handler,
        private readonly SettingsInterface $settings,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/webhooks/stripe', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleStripe'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handleStripe(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload   = $request->get_body();
        $sigHeader = $request->get_header('stripe-signature') ?? '';
        $secret    = $this->getWebhookSecret();

        if ($secret === '') {
            error_log('[duj-wellness] WebhooksController: webhook_secret není nakonfigurovaný.');
            // Vrátíme 200 aby Stripe nepřestal posílat — logicky ale nespracujeme
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_configured'], 200);
        }

        $result = $this->handler->handle($payload, $sigHeader, $secret);

        return new \WP_REST_Response($result['body'], $result['status']);
    }

    private function getWebhookSecret(): string
    {
        // Preferuj konstantu z wp-config.php
        if (defined('DUJ_STRIPE_WEBHOOK_SECRET') && is_string(constant('DUJ_STRIPE_WEBHOOK_SECRET'))) {
            return constant('DUJ_STRIPE_WEBHOOK_SECRET');
        }

        return (string) $this->settings->get('stripe_webhook_secret', '');
    }
}

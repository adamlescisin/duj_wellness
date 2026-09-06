<?php

declare(strict_types=1);

namespace Duj\Wellness\Payment;

use Duj\Wellness\Support\SettingsInterface;
use Stripe\StripeClient;

/**
 * Vytváří StripeGateway instance s klíčem z konfigurace.
 *
 * BEZPEČNOSTNÍ PRAVIDLO: Secret key se NIKDY nezaloguje.
 * Čteme v pořadí: konstanta DUJ_STRIPE_SECRET_KEY → wp_options (stripe_secret_key).
 */
final class StripeGatewayFactory
{
    public static function create(SettingsInterface $settings): StripeGateway
    {
        $secretKey = self::resolveSecretKey($settings);
        $client    = new StripeClient($secretKey);

        return new StripeGateway($client);
    }

    public static function resolveSecretKey(SettingsInterface $settings): string
    {
        // Delegate to Settings which handles test/live mode and all constant names.
        if ($settings instanceof \Duj\Wellness\Support\Settings) {
            return $settings->stripeSecretKey();
        }

        // Fallback for non-Settings implementations (e.g. tests).
        if (defined('DUJ_STRIPE_SECRET_KEY') && is_string(constant('DUJ_STRIPE_SECRET_KEY'))) {
            return constant('DUJ_STRIPE_SECRET_KEY');
        }

        return (string) $settings->get('stripe_secret_key', '');
    }

    public static function resolvePublishableKey(SettingsInterface $settings): string
    {
        if ($settings instanceof \Duj\Wellness\Support\Settings) {
            return $settings->stripePublishableKey();
        }

        if (defined('DUJ_STRIPE_PUBLISHABLE_KEY') && is_string(constant('DUJ_STRIPE_PUBLISHABLE_KEY'))) {
            return constant('DUJ_STRIPE_PUBLISHABLE_KEY');
        }

        return (string) $settings->get('stripe_publishable_key', '');
    }
}

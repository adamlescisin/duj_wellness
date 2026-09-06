<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Support\Settings;

/**
 * Admin REST endpointy pro nastavení pluginu.
 */
final class AdminSettingsController
{
    public function register(): void
    {
        $cb = fn($m, $h) => ['methods' => $m, 'callback' => [$this, $h], 'permission_callback' => [$this, 'can']];

        register_rest_route('duj/v1', '/admin/settings', [
            $cb('GET',   'get'),
            $cb('PATCH', 'patch'),
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function get(\WP_REST_Request $req): \WP_REST_Response
    {
        $s = Settings::instance();

        return new \WP_REST_Response([
            'stripe_mode'                    => $s->stripeMode(),
            'stripe_secret_key_set'          => $s->stripeSecretKey() !== '',
            'stripe_test_publishable_key'    => $s->get('stripe_test_publishable_key', ''),
            'stripe_live_publishable_key'    => $s->get('stripe_live_publishable_key', ''),
            'hold_minutes'                   => $s->holdMinutes(),
            'default_slot_minutes'           => $s->defaultSlotMinutes(),
            'buffer_minutes'                 => $s->bufferMinutes(),
            'calendar_months'                => $s->calendarMonths(),
            'cutoff_enabled'                 => $s->cutoffEnabled(),
            'cutoff_time'                    => $s->cutoffTime(),
            'min_lead_time_minutes'          => $s->minLeadTimeMinutes(),
            'default_accommodation_policy'   => $s->defaultAccommodationPolicy(),
            'accommodation_stale_after_days' => $s->accommodationStaleAfterDays(),
            'stale_policy'                   => $s->stalePolicy(),
            'bank_account_iban'              => $s->bankAccountIban(),
            'bank_account_number'            => $s->bankAccountNumber(),
            'qr_bank_hold_hours'             => $s->qrBankHoldHours(),
            'contact_email'                  => $s->contactEmail(),
            'contact_phone'                  => $s->contactPhone(),
            'address'                        => $s->address(),
            'vop_url'                        => $s->vopUrl(),
            'guests_only_message'            => $s->guestsOnlyMessage(),
            'gdpr_retention_months'          => $s->gdprRetentionMonths(),
            'debug_mode'                     => $s->debugMode(),
            'logo_url'                       => $s->logoUrl(),
        ]);
    }

    public function patch(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $body = $req->get_json_params();
        $s    = Settings::instance();

        $allowed = [
            'stripe_mode'                    => 'sanitize_key',
            'stripe_test_publishable_key'    => 'sanitize_text_field',
            'stripe_live_publishable_key'    => 'sanitize_text_field',
            'hold_minutes'                   => 'intval',
            'default_slot_minutes'           => 'intval',
            'buffer_minutes'                 => 'intval',
            'calendar_months'                => 'intval',
            'cutoff_enabled'                 => 'boolval',
            'cutoff_time'                    => 'sanitize_text_field',
            'min_lead_time_minutes'          => 'intval',
            'default_accommodation_policy'   => 'sanitize_key',
            'accommodation_stale_after_days' => 'intval',
            'stale_policy'                   => 'sanitize_key',
            'bank_account_iban'              => 'sanitize_text_field',
            'bank_account_number'            => 'sanitize_text_field',
            'qr_bank_hold_hours'             => 'intval',
            'contact_email'                  => 'sanitize_email',
            'contact_phone'                  => 'sanitize_text_field',
            'address'                        => 'sanitize_text_field',
            'vop_url'                        => 'esc_url_raw',
            'guests_only_message'            => 'sanitize_textarea_field',
            'gdpr_retention_months'          => 'intval',
            'debug_mode'                     => 'boolval',
            'logo_url'                       => 'esc_url_raw',
        ];

        $enum = [
            'stripe_mode'                  => ['test', 'live'],
            'default_accommodation_policy' => ['ignore', 'guests_only', 'closed'],
            'stale_policy'                 => ['fail_safe', 'warn_only'],
        ];

        $saved = 0;
        foreach ($allowed as $key => $sanitizer) {
            if (!array_key_exists($key, $body)) {
                continue;
            }

            $value = $sanitizer($body[$key]);

            if (isset($enum[$key]) && !in_array($value, $enum[$key], true)) {
                return new \WP_Error('invalid_value', "Neplatná hodnota pro {$key}.", ['status' => 400]);
            }

            $s->set($key, $value);
            $saved++;
        }

        $s->save();

        return new \WP_REST_Response(['saved' => $saved]);
    }
}

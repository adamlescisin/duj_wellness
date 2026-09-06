<?php

declare(strict_types=1);

namespace Duj\Wellness\Support;

/**
 * Typovaná třída nastavení pluginu.
 * Nastavení se ukládají jako jeden serializovaný option 'duj_settings'.
 */
final class Settings implements SettingsInterface
{
    private const OPTION_KEY = 'duj_settings';

    private array $data;

    private static ?self $instance = null;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            $stored = get_option(self::OPTION_KEY, []);
            self::$instance = new self(is_array($stored) ? $stored : []);
        }

        return self::$instance;
    }

    /** Pouze pro testy — resetuje singleton. */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function save(): void
    {
        update_option(self::OPTION_KEY, $this->data, false);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    // ── Stripe ──────────────────────────────────────────────────────────────

    public function stripeMode(): string
    {
        return (string) $this->get('stripe_mode', 'test');
    }

    public function stripePublishableKey(): string
    {
        // Přednost mají konstanty z wp-config.php
        if ($this->stripeMode() === 'live') {
            return defined('DUJ_STRIPE_LIVE_PUBLISHABLE_KEY')
                ? DUJ_STRIPE_LIVE_PUBLISHABLE_KEY
                : (string) $this->get('stripe_live_publishable_key', '');
        }

        return defined('DUJ_STRIPE_TEST_PUBLISHABLE_KEY')
            ? DUJ_STRIPE_TEST_PUBLISHABLE_KEY
            : (string) $this->get('stripe_test_publishable_key', '');
    }

    public function stripeSecretKey(): string
    {
        if ($this->stripeMode() === 'live') {
            return defined('DUJ_STRIPE_SECRET_KEY')
                ? DUJ_STRIPE_SECRET_KEY
                : (string) $this->get('stripe_live_secret_key', '');
        }

        return defined('DUJ_STRIPE_TEST_SECRET_KEY')
            ? DUJ_STRIPE_TEST_SECRET_KEY
            : (string) $this->get('stripe_test_secret_key', '');
    }

    public function stripeWebhookSecret(): string
    {
        return defined('DUJ_STRIPE_WEBHOOK_SECRET')
            ? DUJ_STRIPE_WEBHOOK_SECRET
            : (string) $this->get('stripe_webhook_secret', '');
    }

    public function paymentCaptureMode(): string
    {
        return (string) $this->get('payment_capture_mode', 'manual');
    }

    /** @return string[] */
    public function enabledPaymentMethods(): array
    {
        return (array) $this->get('enabled_methods', ['stripe_card', 'qr_checkout']);
    }

    public function holdMinutes(): int
    {
        return (int) $this->get('hold_minutes', 15);
    }

    // ── Rozvrh ──────────────────────────────────────────────────────────────

    public function defaultSlotMinutes(): int
    {
        return (int) $this->get('default_slot_minutes', 120);
    }

    public function bufferMinutes(): int
    {
        return (int) $this->get('buffer_minutes', 60);
    }

    // ── Cutoff ──────────────────────────────────────────────────────────────

    public function cutoffEnabled(): bool
    {
        return (bool) $this->get('cutoff_enabled', true);
    }

    public function cutoffTime(): string
    {
        return (string) $this->get('cutoff_time', '12:00');
    }

    /** 'wall_clock' | 'fixed_cet' */
    public function cutoffTzMode(): string
    {
        return (string) $this->get('cutoff_tz_mode', 'wall_clock');
    }

    public function minLeadTimeMinutes(): int
    {
        return (int) $this->get('min_lead_time_minutes', 180);
    }

    // ── Ubytování ────────────────────────────────────────────────────────────

    /** URL iCal feedu — preferovaně z wp-config.php, nikdy do logů. */
    public function accommodationIcsUrl(): string
    {
        return defined('DUJ_ACCOMMODATION_ICS_URL')
            ? DUJ_ACCOMMODATION_ICS_URL
            : (string) $this->get('accommodation_ics_url', '');
    }

    /** 'ignore' | 'guests_only' | 'closed' */
    public function defaultAccommodationPolicy(): string
    {
        return (string) $this->get('default_accommodation_policy', 'guests_only');
    }

    /** Po kolika dnech jsou data ubytování považována za zastaralá. */
    public function accommodationStaleAfterDays(): int
    {
        return (int) $this->get('accommodation_stale_after_days', 2);
    }

    /** 'warn_only' | 'fail_safe' */
    public function stalePolicy(): string
    {
        return (string) $this->get('stale_policy', 'fail_safe');
    }

    /** 'closed' | 'guests_only' — jak se počítá den odjezdu hostů. */
    public function checkoutDayPolicy(): string
    {
        return (string) $this->get('checkout_day_policy', 'closed');
    }

    /** Kolik měsíců dopředu se načítá kalendář. */
    public function calendarMonths(): int
    {
        return (int) $this->get('calendar_months', 3);
    }

    // ── QR bank ──────────────────────────────────────────────────────────────

    public function bankAccountIban(): string
    {
        return (string) $this->get('bank_account_iban', '');
    }

    public function bankAccountNumber(): string
    {
        return (string) $this->get('bank_account_number', '');
    }

    public function qrBankHoldHours(): int
    {
        return (int) $this->get('qr_bank_hold_hours', 48);
    }

    // ── Kontakt / GDPR ────────────────────────────────────────────────────────

    public function adminEmail(): string
    {
        return (string) $this->get('admin_email', get_option('admin_email', ''));
    }

    public function contactEmail(): string
    {
        return (string) $this->get('contact_email', 'domecekujosefa@gmail.com');
    }

    public function contactPhone(): string
    {
        return (string) $this->get('contact_phone', '+420 773 454 854');
    }

    public function address(): string
    {
        return (string) $this->get('address', 'Hostín 7, 277 32 Hostín');
    }

    public function gdprRetentionMonths(): int
    {
        return (int) $this->get('gdpr_retention_months', 24);
    }

    // ── Notifikace ────────────────────────────────────────────────────────────

    public function telegramBotToken(): string
    {
        return defined('DUJ_TELEGRAM_BOT_TOKEN')
            ? DUJ_TELEGRAM_BOT_TOKEN
            : (string) $this->get('telegram_bot_token', '');
    }

    public function telegramChatId(): string
    {
        return (string) $this->get('telegram_chat_id', '');
    }

    /** @return string[] */
    public function notifyChannels(): array
    {
        return (array) $this->get('notify_channels', ['email']);
    }

    // ── Vzhled ────────────────────────────────────────────────────────────────

    public function accentColor(): string
    {
        return (string) $this->get('accent_color', '#8B5E3C');
    }

    public function accentContrastColor(): string
    {
        return (string) $this->get('accent_contrast_color', '#ffffff');
    }

    public function textColor(): string
    {
        return (string) $this->get('text_color', '#1a1a1a');
    }

    public function mutedColor(): string
    {
        return (string) $this->get('muted_color', '#6b7280');
    }

    public function borderColor(): string
    {
        return (string) $this->get('border_color', '#e5e7eb');
    }

    public function surfaceColor(): string
    {
        return (string) $this->get('surface_color', '#ffffff');
    }

    public function borderRadius(): string
    {
        return (string) $this->get('border_radius', '8px');
    }

    // ── Texty ─────────────────────────────────────────────────────────────────

    public function consentText(): string
    {
        return (string) $this->get(
            'consent_text',
            'Souhlasím se zpracováním osobních údajů pro účely rezervace.'
        );
    }

    public function vopUrl(): string
    {
        return (string) $this->get('vop_url', '/vop/');
    }

    public function guestsOnlyMessage(): string
    {
        return (string) $this->get(
            'guests_only_message',
            'Termín je vyhrazen ubytovaným hostům. Jste u nás ubytovaní? Zadejte kód.'
        );
    }

    // ── SMS ───────────────────────────────────────────────────────────────────

    public function smsEnabled(): bool
    {
        return (bool) $this->get('sms_enabled', false);
    }

    public function smsGatewayUrl(): string
    {
        return (string) $this->get('sms_gateway_url', '');
    }

    public function smsApiKey(): string
    {
        return defined('DUJ_SMS_API_KEY')
            ? DUJ_SMS_API_KEY
            : (string) $this->get('sms_api_key', '');
    }

    public function smsSender(): string
    {
        return (string) $this->get('sms_sender', '');
    }

    // ── GitHub Deploy ────────────────────────────────────────────────────────

    /** Sdílené tajemství pro ověření GitHub webhooku (HMAC-SHA256). */
    public function deploySecret(): string
    {
        return defined('DUJ_DEPLOY_SECRET')
            ? DUJ_DEPLOY_SECRET
            : (string) $this->get('deploy_secret', '');
    }

    /** Větev, ze které se táhnou změny (výchozí: main). */
    public function deployBranch(): string
    {
        return (string) $this->get('deploy_branch', 'main');
    }

    // ── Ladicí režim ─────────────────────────────────────────────────────────

    public function debugMode(): bool
    {
        return (bool) $this->get('debug_mode', false);
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification\Channels;

use Duj\Wellness\Notification\NotificationChannelInterface;
use Duj\Wellness\Support\SettingsInterface;

/**
 * SMS kanál — odesílá přes generický HTTP SMS gateway (POST).
 *
 * Konfigurace v nastavení pluginu:
 *   sms_enabled     (bool)   — zda je kanál aktivní
 *   sms_gateway_url (string) — URL endpointu, např. https://api.smsapi.cz/sms.do
 *   sms_api_key     (string) — API klíč (Bearer token nebo query param)
 *   sms_sender      (string) — číslo nebo název odesílatele
 *
 * Parametry POST requestu jsou kompatibilní s SMSAPI.cz a GoSMS.cz:
 *   to, from, message, format=json
 * Autorizace: Authorization: Bearer <api_key>
 *
 * BEZPEČNOSTNÍ PRAVIDLO: api_key se nikdy nezaloguje.
 */
final class SmsChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly SettingsInterface $settings,
    ) {}

    public function supports(): bool
    {
        return (bool) $this->settings->get('sms_enabled', false)
            && $this->settings->get('sms_gateway_url', '') !== ''
            && $this->settings->get('sms_api_key', '') !== '';
    }

    public function send(string $to, string $message, array $ctx = []): void
    {
        if (!$this->supports()) {
            throw new \RuntimeException('SmsChannel: kanál není nakonfigurován.');
        }

        $gatewayUrl = (string) $this->settings->get('sms_gateway_url', '');
        $apiKey     = (string) $this->settings->get('sms_api_key', '');
        $sender     = (string) $this->settings->get('sms_sender', '');

        // Normalizuj telefonní číslo — přidej +420 pokud chybí mezinárodní předvolba
        $phone = $this->normalizePhone($to);
        if ($phone === '') {
            throw new \RuntimeException('SmsChannel: neplatné telefonní číslo.');
        }

        // Ořízni SMS na 160 znaků (1 SMS), plaintext bez HTML
        $text = strip_tags($message);
        $text = mb_substr($text, 0, 160);

        $body = [
            'to'      => $phone,
            'message' => $text,
            'format'  => 'json',
        ];

        if ($sender !== '') {
            $body['from'] = $sender;
        }

        $args = [
            'body'    => $body,
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
        ];

        $response = wp_remote_post($gatewayUrl, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('SMS gateway error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("SMS gateway HTTP {$code}");
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone);
        if ($digits === null || $digits === '') {
            return '';
        }

        // Přidej +420 pro česká čísla bez mezinárodní předvolby (9 číslic)
        if (preg_match('/^\d{9}$/', $digits)) {
            return '+420' . $digits;
        }

        // +420xxxxxxxxx nebo jiná mezinárodní předvolba
        if (str_starts_with($digits, '+') || str_starts_with($digits, '00')) {
            return $digits;
        }

        return '';
    }
}

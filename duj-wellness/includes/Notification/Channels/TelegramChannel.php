<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification\Channels;

use Duj\Wellness\Notification\NotificationChannelInterface;
use Duj\Wellness\Support\SettingsInterface;

/**
 * Telegram kanál — odesílá přes Bot API.
 *
 * Konfigurace:
 *   settings: telegram_bot_token, telegram_chat_id
 *
 * Zprávy jsou odesílány jako HTML (parse_mode=HTML).
 * Inline tlačítka Potvrdit/Zamítnout jsou přidána přes context['inline_keyboard'].
 *
 * BEZPEČNOSTNÍ PRAVIDLO: bot_token se nikdy nezaloguje.
 */
final class TelegramChannel implements NotificationChannelInterface
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly SettingsInterface $settings,
    ) {}

    public function supports(): bool
    {
        return $this->getBotToken() !== '' && $this->getDefaultChatId() !== '';
    }

    public function send(string $to, string $message, array $ctx = []): void
    {
        $token  = $this->getBotToken();
        $chatId = $to !== '' ? $to : $this->getDefaultChatId();

        if ($token === '' || $chatId === '') {
            throw new \RuntimeException('TelegramChannel: bot_token nebo chat_id není nastaven.');
        }

        $params = [
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ];

        // Volitelná inline klávesnice (tlačítka Potvrdit / Zamítnout)
        if (!empty($ctx['inline_keyboard'])) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $ctx['inline_keyboard']]);
        }

        $this->post($token, 'sendMessage', $params);
    }

    private function post(string $token, string $method, array $params): void
    {
        $url = self::API_BASE . $token . '/' . $method;

        $args = [
            'body'      => $params,
            'timeout'   => 10,
            'sslverify' => true,
        ];

        if (function_exists('wp_remote_post')) {
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                throw new \RuntimeException('Telegram API error: ' . $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                throw new \RuntimeException('Telegram API HTTP ' . $code);
            }

            return;
        }

        // Fallback (testy / CLI)
        $context  = stream_context_create(['http' => ['method' => 'POST', 'content' => http_build_query($params), 'header' => 'Content-Type: application/x-www-form-urlencoded']]);
        $result   = @file_get_contents($url, false, $context); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ($result === false) {
            throw new \RuntimeException('Telegram API request selhal.');
        }
    }

    private function getBotToken(): string
    {
        if (defined('DUJ_TELEGRAM_BOT_TOKEN') && is_string(constant('DUJ_TELEGRAM_BOT_TOKEN'))) {
            return constant('DUJ_TELEGRAM_BOT_TOKEN');
        }
        return (string) $this->settings->get('telegram_bot_token', '');
    }

    private function getDefaultChatId(): string
    {
        return (string) $this->settings->get('telegram_chat_id', '');
    }
}

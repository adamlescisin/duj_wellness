<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Notification\Channels\TelegramChannel;
use Duj\Wellness\Support\Settings;

/**
 * Admin REST endpointy pro správu notifikací.
 */
final class AdminNotificationsController
{
    public function register(): void
    {
        $cb = fn($m, $h) => ['methods' => $m, 'callback' => [$this, $h], 'permission_callback' => [$this, 'can']];

        register_rest_route('duj/v1', '/admin/notifications/config', [
            $cb('GET',   'getConfig'),
            $cb('PATCH', 'saveConfig'),
        ]);
        register_rest_route('duj/v1', '/admin/notifications/test', [
            $cb('POST', 'test'),
        ]);
        register_rest_route('duj/v1', '/admin/notifications/log', [
            $cb('GET', 'log'),
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function getConfig(\WP_REST_Request $req): \WP_REST_Response
    {
        $settings = Settings::instance();
        $botToken = $settings->get('telegram_bot_token', '');

        return new \WP_REST_Response([
            'telegram_bot_token_set' => $botToken !== '',
            'telegram_chat_id'       => $settings->get('telegram_chat_id', ''),
        ]);
    }

    public function saveConfig(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $body = $req->get_json_params();
        $settings = Settings::instance();

        if (isset($body['telegram_bot_token']) && $body['telegram_bot_token'] !== '') {
            $settings->set('telegram_bot_token', sanitize_text_field($body['telegram_bot_token']));
        }

        if (isset($body['telegram_chat_id'])) {
            $settings->set('telegram_chat_id', sanitize_text_field($body['telegram_chat_id']));
        }

        return new \WP_REST_Response(['saved' => true]);
    }

    public function test(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $settings = Settings::instance();
        $channel  = new TelegramChannel($settings);

        try {
            $channel->send('', __('Test notifikace z Domeček u Josefa wellness pluginu.', 'duj-wellness'));
            return new \WP_REST_Response(['sent' => true]);
        } catch (\Throwable $e) {
            return new \WP_Error('telegram_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function log(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;

        $limit  = min(100, max(1, (int)($req->get_param('per_page') ?? 50)));
        $offset = max(0, ((int)($req->get_param('page') ?? 1) - 1)) * $limit;

        $notifTable   = $wpdb->prefix . 'duj_notifications';
        $bookingTable = $wpdb->prefix . 'duj_bookings';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id, n.booking_id, n.channel, n.template_key, n.status, n.error, n.created_at,
                        b.reference
                 FROM `{$notifTable}` n
                 LEFT JOIN `{$bookingTable}` b ON b.id = n.booking_id
                 ORDER BY n.created_at DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ),
            ARRAY_A
        ) ?? [];

        return new \WP_REST_Response($rows);
    }
}

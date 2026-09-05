<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

use Duj\Wellness\Support\Settings;

/**
 * Stránka Notifikace — Telegram/SMS konfigurace + log.
 */
final class NotificationsPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        global $wpdb;
        $logTable = $wpdb->prefix . 'duj_notifications';
        $logs     = $wpdb->get_results(
            "SELECT n.*, b.reference FROM `{$logTable}` n LEFT JOIN `{$wpdb->prefix}duj_bookings` b ON n.booking_id = b.id ORDER BY n.sent_at DESC LIMIT 100",
            ARRAY_A
        ) ?? [];

        $settings  = Settings::instance();
        $chatId    = esc_attr($settings->telegramChatId());
        $tokenSet  = defined('DUJ_TELEGRAM_BOT_TOKEN') || $settings->telegramBotToken() !== '';
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Notifikace', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <div class="duj-settings-section">
                <h3><?= esc_html__('Telegram', 'duj-wellness') ?></h3>
                <form id="duj-notif-settings-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="tg-token"><?= esc_html__('Bot token', 'duj-wellness') ?></label></th>
                            <td>
                                <?php if ($tokenSet): ?>
                                    <span class="duj-masked-key"><?= esc_html__('Nastaveno (konstanta DUJ_TELEGRAM_BOT_TOKEN nebo option)', 'duj-wellness') ?></span>
                                    <input type="password" id="tg-token" name="telegram_bot_token" placeholder="<?= esc_attr__('Nový token (přepíše uložený)', 'duj-wellness') ?>" style="max-width:400px">
                                <?php else: ?>
                                    <input type="password" id="tg-token" name="telegram_bot_token" style="max-width:400px" placeholder="123456:ABC-DEF...">
                                    <p class="description"><?= esc_html__('Doporučujeme uložit do wp-config.php jako DUJ_TELEGRAM_BOT_TOKEN.', 'duj-wellness') ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tg-chatid"><?= esc_html__('Chat ID', 'duj-wellness') ?></label></th>
                            <td>
                                <input type="text" id="tg-chatid" name="telegram_chat_id" value="<?= $chatId ?>" style="max-width:200px">
                                <p class="description"><?= esc_html__('Číslo chatu nebo skupiny (najdete přes @userinfobot).', 'duj-wellness') ?></p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary"><?= esc_html__('Uložit', 'duj-wellness') ?></button>
                    <button type="button" id="duj-test-telegram" class="button" style="margin-left:.5rem">
                        <?= esc_html__('Poslat testovací zprávu', 'duj-wellness') ?>
                    </button>
                </form>
            </div>

            <h3><?= esc_html__('Log posledních 100 odeslání', 'duj-wellness') ?></h3>
            <table class="duj-notif-log">
                <thead><tr>
                    <th><?= esc_html__('Čas', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Rezervace', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Kanál', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Událost', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Stav', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Chyba', 'duj-wellness') ?></th>
                </tr></thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6"><?= esc_html__('Žádné záznamy.', 'duj-wellness') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><?= esc_html($l['sent_at']) ?></td>
                            <td><?= esc_html($l['reference'] ?? "#{$l['booking_id']}") ?></td>
                            <td><?= esc_html($l['channel']) ?></td>
                            <td><?= esc_html($l['event']) ?></td>
                            <td class="duj-<?= esc_attr($l['status']) ?>"><?= esc_html($l['status']) ?></td>
                            <td><?= esc_html($l['error'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>document.body.dataset.dujPage = 'notifications';</script>
        <?php
    }
}

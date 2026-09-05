<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka E-maily — editor 8 šablon, náhled, test, reset.
 */
final class EmailsPage
{
    private const TEMPLATES = [
        'awaiting_confirmation' => 'Čeká na potvrzení (zákazník)',
        'confirmed'             => 'Potvrzeno (zákazník)',
        'cancelled'             => 'Zrušeno (zákazník)',
        'admin_booking_new'     => 'Nová rezervace (správce)',
        'reminder'              => 'Připomínka (zákazník)',
        'auth_expiring'         => 'Blíží se expirace autorizace (správce)',
        'completed'             => 'Dokončeno (zákazník)',
        'admin_auth_expiring'   => 'Expirace autorizace (správce)',
    ];

    private const PLACEHOLDERS = [
        '{{reference}}', '{{customer_name}}', '{{customer_email}}', '{{customer_phone}}',
        '{{date}}', '{{weekday}}', '{{time_from}}', '{{time_to}}', '{{service_label}}',
        '{{guests}}', '{{price}}', '{{tier_label}}', '{{access_code}}',
        '{{payment_method_label}}', '{{status_label}}', '{{customer_note}}', '{{admin_note}}',
        '{{confirm_url}}', '{{reject_url}}', '{{cancel_url}}', '{{detail_url}}', '{{admin_url}}',
        '{{site_name}}', '{{site_url}}', '{{contact_email}}', '{{contact_phone}}', '{{address}}',
    ];

    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html__('E-mailové šablony', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <div class="duj-template-select">
                <?php foreach (self::TEMPLATES as $key => $label): ?>
                    <button type="button" class="duj-template-btn" data-template="<?= esc_attr($key) ?>">
                        <?= esc_html($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div id="duj-template-editor">
                <table class="form-table">
                    <tr>
                        <th><label for="duj-tpl-subject"><?= esc_html__('Předmět', 'duj-wellness') ?></label></th>
                        <td><input type="text" id="duj-tpl-subject" style="width:100%;max-width:600px"></td>
                    </tr>
                    <tr>
                        <th><?= esc_html__('Placeholdery', 'duj-wellness') ?></th>
                        <td>
                            <div class="duj-placeholder-list">
                                <?php foreach (self::PLACEHOLDERS as $ph): ?>
                                    <code title="<?= esc_attr__('Klik pro vložení do těla', 'duj-wellness') ?>"><?= esc_html($ph) ?></code>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?= esc_html__('Kliknutím na placeholder ho vložíte na aktuální pozici kurzoru v těle e-mailu.', 'duj-wellness') ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="duj-tpl-body"><?= esc_html__('Tělo e-mailu', 'duj-wellness') ?></label></th>
                        <td>
                            <textarea id="duj-tpl-body" rows="16" style="width:100%;max-width:700px;font-family:monospace"></textarea>
                            <p class="description"><?= esc_html__('HTML šablona. Placeholdery ve tvaru {{nazev}} jsou nahrazeny při odeslání.', 'duj-wellness') ?></p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="duj-tpl-save" class="button button-primary"><?= esc_html__('Uložit šablonu', 'duj-wellness') ?></button>
                    <button type="button" id="duj-tpl-reset" class="button"><?= esc_html__('Obnovit výchozí', 'duj-wellness') ?></button>
                    <button type="button" id="duj-tpl-test" class="button"><?= esc_html__('Odeslat testovací e-mail', 'duj-wellness') ?></button>
                </p>
            </div>
        </div>
        <script>document.body.dataset.dujPage = 'emails';</script>
        <?php
    }
}

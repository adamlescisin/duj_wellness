<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka Rezervace v admin menu.
 */
final class BookingsPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        add_filter('admin_body_class', static fn($cls) => $cls . ' duj-page-bookings');

        $table = new BookingsListTable();
        $table->prepare_items();

        $statuses = [
            ''                       => __('Všechny stavy', 'duj-wellness'),
            'pending_payment'        => __('Čekání na platbu', 'duj-wellness'),
            'awaiting_confirmation'  => __('Čeká na potvrzení', 'duj-wellness'),
            'confirmed'              => __('Potvrzeno', 'duj-wellness'),
            'cancelled'              => __('Zrušeno', 'duj-wellness'),
            'expired'                => __('Vypršelo', 'duj-wellness'),
            'completed'              => __('Dokončeno', 'duj-wellness'),
            'rejected'               => __('Zamítnuto', 'duj-wellness'),
        ];

        $currentStatus  = sanitize_text_field($_GET['status']   ?? '');
        $currentService = sanitize_text_field($_GET['service']  ?? '');
        $dateFrom       = sanitize_text_field($_GET['date_from'] ?? '');
        $dateTo         = sanitize_text_field($_GET['date_to']   ?? '');
        $search         = sanitize_text_field($_GET['s']         ?? '');
        $baseUrl        = admin_url('admin.php?page=duj-wellness');

        // CSV export
        if (isset($_GET['export_csv']) && check_admin_referer('duj_export_csv')) {
            self::exportCsv();
        }
        ?>
        <script>document.body.dataset.dujPage = 'bookings';</script>
        <div class="wrap" data-duj-page="bookings">
            <h1 class="wp-heading-inline"><?= esc_html__('Rezervace wellness', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <form id="duj-bookings-form" method="get">
                <input type="hidden" name="page" value="duj-wellness">

                <div class="duj-filters">
                    <label>
                        <?= esc_html__('Stav', 'duj-wellness') ?>
                        <select name="status">
                            <?php foreach ($statuses as $val => $label): ?>
                                <option value="<?= esc_attr($val) ?>" <?= selected($currentStatus, $val, false) ?>><?= esc_html($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?= esc_html__('Služba', 'duj-wellness') ?>
                        <select name="service">
                            <option value=""><?= esc_html__('Všechny', 'duj-wellness') ?></option>
                            <option value="sud" <?= selected($currentService, 'sud', false) ?>><?= esc_html__('Koupací sud', 'duj-wellness') ?></option>
                            <option value="sauna" <?= selected($currentService, 'sauna', false) ?>><?= esc_html__('Sauna', 'duj-wellness') ?></option>
                            <option value="sud+sauna" <?= selected($currentService, 'sud+sauna', false) ?>><?= esc_html__('Sud + sauna', 'duj-wellness') ?></option>
                        </select>
                    </label>
                    <label>
                        <?= esc_html__('Od', 'duj-wellness') ?>
                        <input type="date" name="date_from" value="<?= esc_attr($dateFrom) ?>">
                    </label>
                    <label>
                        <?= esc_html__('Do', 'duj-wellness') ?>
                        <input type="date" name="date_to" value="<?= esc_attr($dateTo) ?>">
                    </label>
                    <label>
                        <?= esc_html__('Hledat', 'duj-wellness') ?>
                        <input type="search" name="s" value="<?= esc_attr($search) ?>" placeholder="<?= esc_attr__('Reference, e-mail, jméno', 'duj-wellness') ?>">
                    </label>
                    <button type="submit" class="button"><?= esc_html__('Filtrovat', 'duj-wellness') ?></button>
                    <a href="<?= esc_url($baseUrl) ?>" class="button"><?= esc_html__('Zrušit filtr', 'duj-wellness') ?></a>
                </div>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value="-1"><?= esc_html__('Hromadné akce', 'duj-wellness') ?></option>
                            <option value="confirm"><?= esc_html__('Potvrdit', 'duj-wellness') ?></option>
                            <option value="reject"><?= esc_html__('Zamítnout', 'duj-wellness') ?></option>
                            <option value="cancel"><?= esc_html__('Zrušit', 'duj-wellness') ?></option>
                        </select>
                        <button type="submit" class="button action"><?= esc_html__('Provést', 'duj-wellness') ?></button>
                        <a href="<?= esc_url(wp_nonce_url($baseUrl . '&export_csv=1', 'duj_export_csv')) ?>" class="button"><?= esc_html__('Export CSV', 'duj-wellness') ?></a>
                    </div>
                </div>

                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    private static function exportCsv(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_bookings';

        $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY booking_date DESC, slot_from ASC", ARRAY_A) ?? [];

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rezervace-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

        fputcsv($out, ['ID','Reference','Datum','Od','Do','Služba','Hosté','Stav','Cena (Kč)','Platba','Zákazník','E-mail','Telefon','Vytvořeno'], ';');

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['reference'],
                $r['booking_date'],
                $r['slot_from'],
                $r['slot_to'],
                $r['combo_key'],
                $r['guests'] ?? '',
                $r['status'],
                number_format((int)$r['amount_minor'] / 100, 2, ',', ' '),
                $r['payment_method'],
                $r['customer_name'] ?? '',
                $r['customer_email'],
                $r['customer_phone'],
                $r['created_at'],
            ], ';');
        }

        fclose($out);
        exit;
    }
}

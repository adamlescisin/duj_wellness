<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka Ubytování — přehled bloků, import CSV, ruční přepsání, sync.
 */
final class AccommodationPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';

        $from   = date('Y-m-d');
        $to     = date('Y-m-d', strtotime('+60 days'));
        $blocks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE block_date BETWEEN %s AND %s ORDER BY block_date ASC",
                $from, $to
            ),
            ARRAY_A
        ) ?? [];

        $syncMeta    = get_option('duj_accommodation_sync_meta', []);
        $lastSync    = $syncMeta['last_sync'] ?? null;
        $lastError   = $syncMeta['last_error'] ?? null;
        $icsUrl      = defined('DUJ_ACCOMMODATION_ICS_URL') ? DUJ_ACCOMMODATION_ICS_URL : '';
        $maskedUrl   = $icsUrl ? substr($icsUrl, 0, 8) . str_repeat('*', max(0, strlen($icsUrl) - 12)) . substr($icsUrl, -4) : '';

        $policyLabels = [
            'ignore'      => __('Ignorovat', 'duj-wellness'),
            'guests_only' => __('Jen ubytovaní', 'duj-wellness'),
            'closed'      => __('Zavřeno', 'duj-wellness'),
        ];
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Ubytování', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <!-- Sync status -->
            <div class="duj-settings-section">
                <h3><?= esc_html__('Synchronizace dat', 'duj-wellness') ?></h3>
                <?php if ($maskedUrl): ?>
                    <p><?= esc_html__('iCal URL (z wp-config.php):', 'duj-wellness') ?> <code class="duj-masked-key"><?= esc_html($maskedUrl) ?></code></p>
                <?php else: ?>
                    <p><?= esc_html__('iCal URL není nastavena. Přidejte do wp-config.php: define(\'DUJ_ACCOMMODATION_ICS_URL\', \'…\');', 'duj-wellness') ?></p>
                <?php endif; ?>
                <?php if ($lastSync): ?>
                    <p>
                        <?= esc_html__('Naposledy synchronizováno:', 'duj-wellness') ?>
                        <?= esc_html($lastSync) ?>
                        (<?= esc_html(human_time_diff(strtotime($lastSync))) ?> <?= esc_html__('zpět', 'duj-wellness') ?>)
                    </p>
                <?php endif; ?>
                <?php if ($lastError): ?>
                    <p class="duj-notice duj-notice--error"><?= esc_html__('Poslední chyba:', 'duj-wellness') ?> <?= esc_html($lastError) ?></p>
                <?php endif; ?>
                <button type="button" class="button button-primary" id="duj-sync-now">
                    <?= esc_html__('Synchronizovat teď', 'duj-wellness') ?>
                </button>
            </div>

            <!-- Upcoming blocks -->
            <h3><?= esc_html__('Obsazené dny (nejbližších 60 dnů)', 'duj-wellness') ?></h3>
            <table class="duj-accom-list">
                <thead><tr>
                    <th><?= esc_html__('Datum', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Politika', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Zdroj', 'duj-wellness') ?></th>
                    <th><?= esc_html__('Manuální', 'duj-wellness') ?></th>
                </tr></thead>
                <tbody>
                    <?php if (empty($blocks)): ?>
                        <tr><td colspan="4"><?= esc_html__('Žádné obsazené dny.', 'duj-wellness') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($blocks as $b): ?>
                        <tr>
                            <td><?= esc_html($b['block_date']) ?></td>
                            <td>
                                <select data-accom-policy="<?= esc_attr($b['block_date']) ?>"
                                        data-original="<?= esc_attr($b['policy']) ?>">
                                    <?php foreach ($policyLabels as $val => $label): ?>
                                        <option value="<?= esc_attr($val) ?>" <?= selected($b['policy'], $val, false) ?>><?= esc_html($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><?= esc_html($b['source'] ?? 'ics') ?></td>
                            <td><?= (int)($b['is_manual'] ?? 0) ? '✓' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- CSV import -->
            <div class="duj-settings-section" style="margin-top:2rem">
                <h3><?= esc_html__('Import CSV z WP Booking System', 'duj-wellness') ?></h3>
                <form id="duj-csv-import-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('duj_csv_import', '_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="csv-file"><?= esc_html__('CSV soubor', 'duj-wellness') ?></label></th>
                            <td>
                                <input type="file" id="csv-file" name="csv_file" accept=".csv" required>
                                <p class="description"><?= esc_html__('Export z WP Booking System → CSV. Bude automaticky mapován.', 'duj-wellness') ?></p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" name="action" value="preview" class="button"><?= esc_html__('Náhled dopadu', 'duj-wellness') ?></button>
                    <button type="submit" name="action" value="import" class="button button-primary"><?= esc_html__('Importovat', 'duj-wellness') ?></button>
                    <div id="duj-csv-preview" class="duj-bulk-preview" style="display:none"></div>
                </form>
            </div>
        </div>
        <script>document.body.dataset.dujPage = 'accommodation';</script>
        <?php
    }
}

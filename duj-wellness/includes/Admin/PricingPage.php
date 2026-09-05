<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka Ceník — cenové hladiny, matice cen, přístupové kódy.
 */
final class PricingPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        global $wpdb;
        $tiersTable  = $wpdb->prefix . 'duj_price_tiers';
        $pricesTable = $wpdb->prefix . 'duj_prices';
        $codesTable  = $wpdb->prefix . 'duj_access_codes';

        $tiers  = $wpdb->get_results("SELECT * FROM `{$tiersTable}` ORDER BY sort_order ASC", ARRAY_A) ?? [];
        $prices = $wpdb->get_results("SELECT * FROM `{$pricesTable}` WHERE is_active = 1 ORDER BY tier_slug, combo_key, priority DESC", ARRAY_A) ?? [];
        $codes  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$codesTable}` WHERE is_active = 1 AND (valid_to IS NULL OR valid_to >= %s) ORDER BY created_at DESC LIMIT 50", date('Y-m-d')),
            ARRAY_A
        ) ?? [];

        $combos     = ['sud' => 'Koupací sud', 'sauna' => 'Sauna', 'sud+sauna' => 'Sud + sauna'];
        $priceIndex = [];
        foreach ($prices as $p) {
            $priceIndex[$p['tier_slug'] . '|' . $p['combo_key']] = $p;
        }
        ?>
        <div class="wrap" id="duj-pricing-page">
            <h1><?= esc_html__('Ceník', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <nav class="duj-admin-tabs">
                <a href="#tab-tiers"><?= esc_html__('Hladiny', 'duj-wellness') ?></a>
                <a href="#tab-prices"><?= esc_html__('Ceny', 'duj-wellness') ?></a>
                <a href="#tab-codes"><?= esc_html__('Přístupové kódy', 'duj-wellness') ?></a>
            </nav>

            <!-- Tab A: Tiers -->
            <div class="duj-tab-panel" id="tab-tiers">
                <table class="widefat fixed">
                    <thead><tr>
                        <th><?= esc_html__('Hladina', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Popis', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Výchozí', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Vyžaduje kód', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Aktivní', 'duj-wellness') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($tiers as $t): ?>
                            <tr>
                                <td><?= esc_html($t['label']) ?> <code><?= esc_html($t['slug']) ?></code></td>
                                <td>—</td>
                                <td><?= (int)$t['is_default'] ? '✓' : '' ?></td>
                                <td><?= (int)$t['requires_code'] ? '✓' : '' ?></td>
                                <td><?= (int)$t['is_active'] ? '✓' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab B: Price matrix -->
            <div class="duj-tab-panel" id="tab-prices">
                <p><?= esc_html__('Ceny jsou v Kč. Uložte tlačítkem níže.', 'duj-wellness') ?></p>
                <form id="duj-price-matrix-form">
                    <table class="duj-price-matrix">
                        <thead>
                            <tr>
                                <th><?= esc_html__('Hladina', 'duj-wellness') ?></th>
                                <?php foreach ($combos as $ck => $cl): ?>
                                    <th><?= esc_html($cl) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tiers as $t): ?>
                                <?php if (!(int)$t['is_active']) continue; ?>
                                <tr>
                                    <td><?= esc_html($t['label']) ?></td>
                                    <?php foreach ($combos as $ck => $cl):
                                        $key = $t['slug'] . '|' . $ck;
                                        $p   = $priceIndex[$key] ?? null;
                                        $val = $p ? number_format((int)$p['amount_minor'] / 100, 0, ',', '') : '';
                                        $pid = $p ? (int)$p['id'] : 0;
                                    ?>
                                        <td>
                                            <?php if ($pid): ?>
                                                <input type="number" min="0" step="1" value="<?= esc_attr($val) ?>"
                                                    data-price-id="<?= $pid ?>"
                                                    placeholder="<?= esc_attr__('Kč', 'duj-wellness') ?>">
                                            <?php else: ?>
                                                <em><?= esc_html__('Nenalezeno', 'duj-wellness') ?></em>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="submit" class="button button-primary"><?= esc_html__('Uložit ceny', 'duj-wellness') ?></button></p>
                </form>
            </div>

            <!-- Tab C: Access codes -->
            <div class="duj-tab-panel" id="tab-codes">
                <h3><?= esc_html__('Vygenerovat nový kód', 'duj-wellness') ?></h3>
                <form id="duj-gen-code-form" style="max-width:500px">
                    <table class="form-table">
                        <tr>
                            <th><label for="code-tier"><?= esc_html__('Hladina', 'duj-wellness') ?></label></th>
                            <td>
                                <select id="code-tier" name="tier_slug">
                                    <?php foreach ($tiers as $t): ?>
                                        <?php if ((int)$t['requires_code']): ?>
                                            <option value="<?= esc_attr($t['slug']) ?>"><?= esc_html($t['label']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="code-label"><?= esc_html__('Popis (pro správce)', 'duj-wellness') ?></label></th>
                            <td><input type="text" id="code-label" name="label" required placeholder="<?= esc_attr__('Např. Rodina Novákových 15.9.', 'duj-wellness') ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="code-from"><?= esc_html__('Platný od', 'duj-wellness') ?></label></th>
                            <td><input type="date" id="code-from" name="valid_from"></td>
                        </tr>
                        <tr>
                            <th><label for="code-to"><?= esc_html__('Platný do', 'duj-wellness') ?></label></th>
                            <td><input type="date" id="code-to" name="valid_to"></td>
                        </tr>
                        <tr>
                            <th><label for="code-max"><?= esc_html__('Max. použití', 'duj-wellness') ?></label></th>
                            <td><input type="number" id="code-max" name="max_uses" min="1" placeholder="<?= esc_attr__('Neomezeno', 'duj-wellness') ?>"></td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary"><?= esc_html__('Vygenerovat kód', 'duj-wellness') ?></button>
                </form>

                <h3 style="margin-top:1.5rem"><?= esc_html__('Aktivní kódy', 'duj-wellness') ?></h3>
                <table class="widefat fixed">
                    <thead><tr>
                        <th><?= esc_html__('Kód', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Hladina', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Popis', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Platnost', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Použití', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Akce', 'duj-wellness') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($codes)): ?>
                            <tr><td colspan="6"><?= esc_html__('Žádné aktivní kódy.', 'duj-wellness') ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($codes as $c): ?>
                            <tr>
                                <td><code><?= esc_html($c['code']) ?></code></td>
                                <td><?= esc_html($c['tier_slug']) ?></td>
                                <td><?= esc_html($c['label'] ?? '') ?></td>
                                <td>
                                    <?= esc_html($c['valid_from'] ?? '∞') ?>
                                    –
                                    <?= esc_html($c['valid_to'] ?? '∞') ?>
                                </td>
                                <td><?= (int)$c['used_count'] ?> / <?= esc_html($c['max_uses'] ?? '∞') ?></td>
                                <td>
                                    <button type="button" class="button button-small"
                                        onclick="navigator.clipboard.writeText('<?= esc_attr($c['code']) ?>')"
                                        title="<?= esc_attr__('Kopírovat kód', 'duj-wellness') ?>">
                                        <?= esc_html__('Kopírovat', 'duj-wellness') ?>
                                    </button>
                                    <button type="button" class="button button-small" data-deactivate-code="<?= (int)$c['id'] ?>">
                                        <?= esc_html__('Deaktivovat', 'duj-wellness') ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>document.body.dataset.dujPage = 'pricing';</script>
        <?php
    }
}

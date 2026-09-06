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

        $combos     = ['sud' => 'Koupací sud', 'sauna' => 'Sauna', 'sauna+sud' => 'Sauna + koupací sud'];
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
                <p><?= esc_html__('Upravte hladiny níže a uložte tlačítkem.', 'duj-wellness') ?></p>
                <form id="duj-tiers-form">
                <table class="widefat fixed">
                    <thead><tr>
                        <th style="width:120px"><?= esc_html__('Slug', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Název', 'duj-wellness') ?></th>
                        <th style="width:90px"><?= esc_html__('Cutoff', 'duj-wellness') ?></th>
                        <th style="width:50px"><?= esc_html__('Pořadí', 'duj-wellness') ?></th>
                        <th style="width:70px"><?= esc_html__('Kód?', 'duj-wellness') ?></th>
                        <th style="width:70px"><?= esc_html__('Ve formuláři', 'duj-wellness') ?></th>
                        <th style="width:70px"><?= esc_html__('Aktivní', 'duj-wellness') ?></th>
                        <th style="width:70px"><?= esc_html__('Akce', 'duj-wellness') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($tiers as $t): ?>
                            <tr data-tier-id="<?= (int)$t['id'] ?>">
                                <td><code><?= esc_html($t['slug']) ?></code></td>
                                <td><input type="text" name="label" value="<?= esc_attr($t['label']) ?>" class="regular-text" required></td>
                                <td>
                                    <select name="cutoff_mode" style="width:100%">
                                        <?php foreach (['inherit' => 'Inherit', 'lead_time_only' => 'Lead time', 'none' => 'None'] as $v => $l): ?>
                                            <option value="<?= esc_attr($v) ?>" <?= (($t['cutoff_mode'] ?? 'inherit') === $v) ? 'selected' : '' ?>><?= esc_html($l) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="sort_order" value="<?= (int)$t['sort_order'] ?>" min="0" style="width:50px"></td>
                                <td style="text-align:center"><input type="checkbox" name="requires_code" <?= (int)$t['requires_code'] ? 'checked' : '' ?>></td>
                                <td style="text-align:center"><input type="checkbox" name="show_in_form"  <?= (int)$t['show_in_form']  ? 'checked' : '' ?>></td>
                                <td style="text-align:center"><input type="checkbox" name="is_active"     <?= (int)$t['is_active']     ? 'checked' : '' ?>></td>
                                <td><button type="button" class="button button-small" data-delete-tier="<?= (int)$t['id'] ?>"><?= esc_html__('Smazat', 'duj-wellness') ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:.75rem"><button type="submit" class="button button-primary"><?= esc_html__('Uložit hladiny', 'duj-wellness') ?></button></p>
                </form>

                <h3 style="margin-top:2rem"><?= esc_html__('Přidat hladinu', 'duj-wellness') ?></h3>
                <form id="duj-add-tier-form" style="max-width:540px">
                    <table class="form-table">
                        <tr>
                            <th><label for="tier-slug"><?= esc_html__('Slug (unikátní, neměnný)', 'duj-wellness') ?></label></th>
                            <td><input type="text" id="tier-slug" name="slug" required pattern="[a-z0-9_\-]+" class="regular-text" placeholder="napr_ubytovani"></td>
                        </tr>
                        <tr>
                            <th><label for="tier-label"><?= esc_html__('Název', 'duj-wellness') ?></label></th>
                            <td><input type="text" id="tier-label" name="label" required class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="tier-cutoff"><?= esc_html__('Cutoff mode', 'duj-wellness') ?></label></th>
                            <td>
                                <select id="tier-cutoff" name="cutoff_mode">
                                    <option value="inherit">Inherit</option>
                                    <option value="lead_time_only">Lead time only</option>
                                    <option value="none">None</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?= esc_html__('Možnosti', 'duj-wellness') ?></th>
                            <td>
                                <label><input type="checkbox" name="requires_code"> <?= esc_html__('Vyžaduje přístupový kód', 'duj-wellness') ?></label><br>
                                <label><input type="checkbox" name="show_in_form" checked> <?= esc_html__('Zobrazit ve formuláři', 'duj-wellness') ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="tier-order"><?= esc_html__('Pořadí', 'duj-wellness') ?></label></th>
                            <td><input type="number" id="tier-order" name="sort_order" value="10" min="0" style="width:70px"></td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary"><?= esc_html__('Přidat hladinu', 'duj-wellness') ?></button>
                </form>
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

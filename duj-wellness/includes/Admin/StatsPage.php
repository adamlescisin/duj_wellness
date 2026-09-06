<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka Statistiky.
 */
final class StatsPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Statistiky wellness', 'duj-wellness') ?></h1>

            <div class="duj-stats-toolbar" style="margin:16px 0;display:flex;gap:12px;align-items:center;">
                <label><?= esc_html__('Období:', 'duj-wellness') ?>
                    <select id="duj-stats-period">
                        <option value="month"><?= esc_html__('Tento měsíc', 'duj-wellness') ?></option>
                        <option value="quarter"><?= esc_html__('Čtvrtletí', 'duj-wellness') ?></option>
                        <option value="year" selected><?= esc_html__('Rok', 'duj-wellness') ?></option>
                    </select>
                </label>
                <span id="duj-stats-loading" hidden><?= esc_html__('Načítám…', 'duj-wellness') ?></span>
            </div>

            <!-- KPI karty -->
            <div id="duj-stats-kpi" class="duj-stats-kpi" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
                <div class="duj-kpi-card"><span class="duj-kpi-label"><?= esc_html__('Celkový obrat (Kč)', 'duj-wellness') ?></span><span class="duj-kpi-value" id="kpi-revenue">—</span></div>
                <div class="duj-kpi-card"><span class="duj-kpi-label"><?= esc_html__('Průměrná rezervace (Kč)', 'duj-wellness') ?></span><span class="duj-kpi-value" id="kpi-avg">—</span></div>
                <div class="duj-kpi-card"><span class="duj-kpi-label"><?= esc_html__('Unikátní zákazníci', 'duj-wellness') ?></span><span class="duj-kpi-value" id="kpi-customers">—</span></div>
                <div class="duj-kpi-card"><span class="duj-kpi-label"><?= esc_html__('Potvrzených rezervací', 'duj-wellness') ?></span><span class="duj-kpi-value" id="kpi-confirmed">—</span></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                <!-- Měsíční přehled -->
                <div class="duj-stats-section">
                    <h3><?= esc_html__('Rezervace a obrat po měsících', 'duj-wellness') ?></h3>
                    <table class="widefat duj-stats-table" id="tbl-monthly">
                        <thead><tr>
                            <th><?= esc_html__('Měsíc', 'duj-wellness') ?></th>
                            <th><?= esc_html__('Rezervace', 'duj-wellness') ?></th>
                            <th><?= esc_html__('Obrat (Kč)', 'duj-wellness') ?></th>
                        </tr></thead>
                        <tbody id="tbody-monthly"><tr><td colspan="3"><?= esc_html__('Načítám…', 'duj-wellness') ?></td></tr></tbody>
                    </table>
                </div>

                <!-- Podle služby -->
                <div class="duj-stats-section">
                    <h3><?= esc_html__('Podle služby', 'duj-wellness') ?></h3>
                    <table class="widefat duj-stats-table" id="tbl-service">
                        <thead><tr>
                            <th><?= esc_html__('Služba', 'duj-wellness') ?></th>
                            <th><?= esc_html__('Rezervace', 'duj-wellness') ?></th>
                            <th><?= esc_html__('Obrat (Kč)', 'duj-wellness') ?></th>
                        </tr></thead>
                        <tbody id="tbody-service"><tr><td colspan="3"><?= esc_html__('Načítám…', 'duj-wellness') ?></td></tr></tbody>
                    </table>

                    <h3 style="margin-top:24px;"><?= esc_html__('Statusy', 'duj-wellness') ?></h3>
                    <table class="widefat duj-stats-table" id="tbl-status">
                        <thead><tr>
                            <th><?= esc_html__('Status', 'duj-wellness') ?></th>
                            <th><?= esc_html__('Počet', 'duj-wellness') ?></th>
                        </tr></thead>
                        <tbody id="tbody-status"><tr><td colspan="2"><?= esc_html__('Načítám…', 'duj-wellness') ?></td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>document.body.dataset.dujPage = 'stats';</script>
        <?php
    }
}

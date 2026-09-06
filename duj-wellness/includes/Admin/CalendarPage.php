<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka Kalendář — měsíční přehled obsazenosti.
 * Data načítá admin.js přes REST /admin/calendar.
 */
final class CalendarPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        $year  = max(2020, min(2099, (int) ($_GET['year']  ?? idate('Y'))));
        $month = max(1,    min(12,   (int) ($_GET['month'] ?? idate('m'))));
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Kalendář', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <div class="duj-cal-legend">
                <strong><?= esc_html__('Legenda:', 'duj-wellness') ?></strong>
                <span class="duj-cal-legend-item"><span class="duj-dot duj-dot--available"></span><?= esc_html__('Volno', 'duj-wellness') ?></span>
                <span class="duj-cal-legend-item"><span class="duj-dot duj-dot--partial"></span><?= esc_html__('Čeká na platbu', 'duj-wellness') ?></span>
                <span class="duj-cal-legend-item"><span class="duj-dot duj-dot--booked"></span><?= esc_html__('Obsazeno', 'duj-wellness') ?></span>
                <span class="duj-cal-legend-item"><span class="duj-dot duj-dot--closed"></span><?= esc_html__('Zavřeno / bez rozvrhu', 'duj-wellness') ?></span>
                <span style="color:#50575e;font-size:.82rem">🛁 = Sud &nbsp; 🔥 = Sauna</span>
            </div>

            <div id="duj-admin-calendar"
                data-year="<?= esc_attr((string) $year) ?>"
                data-month="<?= esc_attr((string) $month) ?>"
                data-duj-page="calendar">
                <div class="duj-spinner"></div>
            </div>
        </div>
        <script>document.body.dataset.dujPage = 'calendar';</script>
        <?php
    }
}

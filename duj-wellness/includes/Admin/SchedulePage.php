<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

/**
 * Stránka Rozvrh — pravidla, generátor slotů, výjimky, hromadná úprava.
 */
final class SchedulePage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        global $wpdb;
        $rulesTable  = $wpdb->prefix . 'duj_schedule_rules';
        $overTable   = $wpdb->prefix . 'duj_schedule_overrides';

        $rules     = $wpdb->get_results("SELECT * FROM `{$rulesTable}` ORDER BY weekday ASC, time_from ASC", ARRAY_A) ?? [];
        $overrides = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$overTable}` WHERE override_date >= %s ORDER BY override_date ASC LIMIT 60", date('Y-m-d')),
            ARRAY_A
        ) ?? [];

        $weekdays = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];
        ?>
        <div class="wrap" id="duj-schedule-page">
            <h1><?= esc_html__('Rozvrh', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <nav class="duj-admin-tabs">
                <a href="#tab-rules"><?= esc_html__('Pravidla', 'duj-wellness') ?></a>
                <a href="#tab-generator"><?= esc_html__('Generátor slotů', 'duj-wellness') ?></a>
                <a href="#tab-overrides"><?= esc_html__('Výjimky', 'duj-wellness') ?></a>
                <a href="#tab-bulk"><?= esc_html__('Hromadná úprava', 'duj-wellness') ?></a>
            </nav>

            <!-- Tab A: Rules -->
            <div class="duj-tab-panel" id="tab-rules">
                <table class="widefat fixed">
                    <thead><tr>
                        <th><?= esc_html__('Den', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Čas', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Popis', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Platnost od', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Platnost do', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Aktivní', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Akce', 'duj-wellness') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr><td colspan="7"><?= esc_html__('Žádná pravidla.', 'duj-wellness') ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($rules as $r): ?>
                            <tr>
                                <td><?= esc_html($weekdays[(int)$r['weekday']] ?? $r['weekday']) ?></td>
                                <td><?= esc_html(substr($r['time_from'],0,5)) ?>–<?= esc_html(substr($r['time_to'],0,5)) ?></td>
                                <td><?= esc_html($r['label'] ?? '') ?></td>
                                <td><?= esc_html($r['valid_from'] ?? '—') ?></td>
                                <td><?= esc_html($r['valid_to']   ?? '—') ?></td>
                                <td><?= (int)$r['is_active'] ? '✓' : '—' ?></td>
                                <td><button type="button" class="button button-small" data-delete-rule="<?= (int)$r['id'] ?>"><?= esc_html__('Smazat', 'duj-wellness') ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab B: Slot generator -->
            <div class="duj-tab-panel" id="tab-generator">
                <form id="duj-slot-gen-form" style="max-width:600px">
                    <table class="form-table">
                        <tr>
                            <th><label for="sgen-from"><?= esc_html__('Okno od', 'duj-wellness') ?></label></th>
                            <td><input type="time" id="sgen-from" name="time_from" value="16:00" required></td>
                        </tr>
                        <tr>
                            <th><label for="sgen-to"><?= esc_html__('Okno do', 'duj-wellness') ?></label></th>
                            <td><input type="time" id="sgen-to" name="time_to" value="22:00" required></td>
                        </tr>
                        <tr>
                            <th><label for="sgen-slot"><?= esc_html__('Délka slotu (min)', 'duj-wellness') ?></label></th>
                            <td><input type="number" id="sgen-slot" name="slot_minutes" value="120" min="30" max="480" required></td>
                        </tr>
                        <tr>
                            <th><label for="sgen-buf"><?= esc_html__('Technická pauza (min)', 'duj-wellness') ?></label></th>
                            <td><input type="number" id="sgen-buf" name="buffer_minutes" value="60" min="0" max="240" required></td>
                        </tr>
                        <tr>
                            <th><?= esc_html__('Dny v týdnu', 'duj-wellness') ?></th>
                            <td>
                                <div class="duj-weekday-picker">
                                    <?php foreach ($weekdays as $num => $label): ?>
                                        <label><input type="checkbox" name="weekdays[]" value="<?= $num ?>" checked> <?= esc_html($label) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" name="action" value="preview" class="button"><?= esc_html__('Náhled', 'duj-wellness') ?></button>
                    <button type="submit" name="action" value="save" class="button button-primary"><?= esc_html__('Uložit jako pravidla', 'duj-wellness') ?></button>
                    <div id="duj-slot-preview" class="duj-slot-preview" style="display:none"></div>
                </form>
            </div>

            <!-- Tab C: Overrides -->
            <div class="duj-tab-panel" id="tab-overrides">
                <h3><?= esc_html__('Přidat výjimku', 'duj-wellness') ?></h3>
                <form id="duj-override-form" style="max-width:500px">
                    <table class="form-table">
                        <tr>
                            <th><label for="ov-date"><?= esc_html__('Datum', 'duj-wellness') ?></label></th>
                            <td><input type="date" id="ov-date" name="override_date" required min="<?= esc_attr(date('Y-m-d')) ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="ov-mode"><?= esc_html__('Režim', 'duj-wellness') ?></label></th>
                            <td>
                                <select id="ov-mode" name="mode">
                                    <option value="closed"><?= esc_html__('Zavřeno', 'duj-wellness') ?></option>
                                    <option value="custom"><?= esc_html__('Vlastní sloty', 'duj-wellness') ?></option>
                                    <option value="guests_only"><?= esc_html__('Jen ubytovaní', 'duj-wellness') ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ov-note"><?= esc_html__('Poznámka', 'duj-wellness') ?></label></th>
                            <td><input type="text" id="ov-note" name="note" placeholder="<?= esc_attr__('Nepovinné', 'duj-wellness') ?>"></td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary"><?= esc_html__('Přidat výjimku', 'duj-wellness') ?></button>
                </form>

                <h3 style="margin-top:1.5rem"><?= esc_html__('Nadcházející výjimky', 'duj-wellness') ?></h3>
                <table class="widefat fixed">
                    <thead><tr>
                        <th><?= esc_html__('Datum', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Režim', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Poznámka', 'duj-wellness') ?></th>
                        <th><?= esc_html__('Akce', 'duj-wellness') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($overrides)): ?>
                            <tr><td colspan="4"><?= esc_html__('Žádné výjimky.', 'duj-wellness') ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($overrides as $ov): ?>
                            <tr>
                                <td><?= esc_html($ov['override_date']) ?></td>
                                <td><?= esc_html($ov['mode']) ?></td>
                                <td><?= esc_html($ov['note'] ?? '') ?></td>
                                <td><button type="button" class="button button-small" data-delete-override="<?= (int)$ov['id'] ?>"><?= esc_html__('Smazat', 'duj-wellness') ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab D: Bulk edit -->
            <div class="duj-tab-panel" id="tab-bulk">
                <form id="duj-schedule-bulk-form" style="max-width:600px">
                    <table class="form-table">
                        <tr>
                            <th><label for="bulk-from"><?= esc_html__('Od data', 'duj-wellness') ?></label></th>
                            <td><input type="date" id="bulk-from" name="date_from" required min="<?= esc_attr(date('Y-m-d')) ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="bulk-to"><?= esc_html__('Do data', 'duj-wellness') ?></label></th>
                            <td><input type="date" id="bulk-to" name="date_to" required min="<?= esc_attr(date('Y-m-d')) ?>"></td>
                        </tr>
                        <tr>
                            <th><?= esc_html__('Dny v týdnu', 'duj-wellness') ?></th>
                            <td>
                                <div class="duj-weekday-picker">
                                    <?php foreach ($weekdays as $num => $label): ?>
                                        <label><input type="checkbox" name="weekdays[]" value="<?= $num ?>" checked> <?= esc_html($label) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk-action"><?= esc_html__('Akce', 'duj-wellness') ?></label></th>
                            <td>
                                <select id="bulk-action" name="bulk_action">
                                    <option value="close"><?= esc_html__('Zavřít', 'duj-wellness') ?></option>
                                    <option value="open"><?= esc_html__('Otevřít (dle pravidel)', 'duj-wellness') ?></option>
                                    <option value="set_slots"><?= esc_html__('Nastavit vlastní sloty', 'duj-wellness') ?></option>
                                    <option value="delete_overrides"><?= esc_html__('Smazat výjimky', 'duj-wellness') ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk-slot-from"><?= esc_html__('Čas od (pro vlastní sloty)', 'duj-wellness') ?></label></th>
                            <td><input type="time" id="bulk-slot-from" name="time_from"></td>
                        </tr>
                        <tr>
                            <th><label for="bulk-slot-to"><?= esc_html__('Čas do', 'duj-wellness') ?></label></th>
                            <td><input type="time" id="bulk-slot-to" name="time_to"></td>
                        </tr>
                        <tr>
                            <th><label for="bulk-slot-min"><?= esc_html__('Délka slotu (min)', 'duj-wellness') ?></label></th>
                            <td><input type="number" id="bulk-slot-min" name="slot_minutes" min="30" max="480"></td>
                        </tr>
                    </table>
                    <button type="submit" name="action" value="preview" class="button"><?= esc_html__('Náhled dopadu', 'duj-wellness') ?></button>
                    <button type="submit" name="action" value="apply" class="button button-primary"><?= esc_html__('Provést', 'duj-wellness') ?></button>
                    <div id="duj-bulk-preview" class="duj-bulk-preview" style="display:none"></div>
                </form>
            </div>
        </div>
        <script>document.body.dataset.dujPage = 'schedule';</script>
        <?php
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

use Duj\Wellness\Support\Settings;

/**
 * Stránka Nastavení — všechna nastavení pluginu.
 */
final class SettingsPage
{
    public static function render(): void
    {
        if (!current_user_can('duj_manage_bookings')) {
            wp_die(__('Přístup odepřen.', 'duj-wellness'));
        }

        $s = Settings::instance();

        $stripeSecretSet = $s->stripeSecretKey() !== '';
        $stripePubKey    = $s->stripePublishableKey();
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Nastavení wellness', 'duj-wellness') ?></h1>
            <div class="duj-notice-area"></div>

            <form id="duj-settings-form">
                <?php wp_nonce_field('duj_settings_save', '_nonce'); ?>

                <!-- Stripe -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Stripe — platby', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Režim', 'duj-wellness') ?></label>
                        <div>
                            <select name="stripe_mode">
                                <option value="test" <?= selected($s->stripeMode(), 'test', false) ?>><?= esc_html__('Testovací', 'duj-wellness') ?></option>
                                <option value="live" <?= selected($s->stripeMode(), 'live', false) ?>><?= esc_html__('Ostrý', 'duj-wellness') ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Secret key', 'duj-wellness') ?></label>
                        <div>
                            <?php if ($stripeSecretSet): ?>
                                <span class="duj-masked-key"><?= esc_html__('Nastaveno (z konstant wp-config.php nebo option)', 'duj-wellness') ?></span>
                            <?php else: ?>
                                <p class="description"><?= esc_html__('Nastavte v wp-config.php jako DUJ_STRIPE_SECRET_KEY nebo DUJ_STRIPE_TEST_SECRET_KEY.', 'duj-wellness') ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Publishable key (test)', 'duj-wellness') ?></label>
                        <div>
                            <input type="text" name="stripe_test_publishable_key"
                                value="<?= esc_attr((string)$s->get('stripe_test_publishable_key', '')) ?>"
                                placeholder="pk_test_…">
                        </div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Publishable key (live)', 'duj-wellness') ?></label>
                        <div>
                            <input type="text" name="stripe_live_publishable_key"
                                value="<?= esc_attr((string)$s->get('stripe_live_publishable_key', '')) ?>"
                                placeholder="pk_live_…">
                        </div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Hold (min)', 'duj-wellness') ?></label>
                        <div>
                            <input type="number" name="hold_minutes" value="<?= esc_attr((string)$s->holdMinutes()) ?>" min="5" max="60">
                            <p class="description"><?= esc_html__('Jak dlouho je slot blokovaný při čekání na platbu.', 'duj-wellness') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Rozvrh -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Rozvrh slotů', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Výchozí délka slotu (min)', 'duj-wellness') ?></label>
                        <div><input type="number" name="default_slot_minutes" value="<?= esc_attr((string)$s->defaultSlotMinutes()) ?>" min="30" max="480"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Technická pauza (min)', 'duj-wellness') ?></label>
                        <div><input type="number" name="buffer_minutes" value="<?= esc_attr((string)$s->bufferMinutes()) ?>" min="0" max="240"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Délka kalendáře (měsíce)', 'duj-wellness') ?></label>
                        <div><input type="number" name="calendar_months" value="<?= esc_attr((string)$s->calendarMonths()) ?>" min="1" max="12"></div>
                    </div>
                </div>

                <!-- Cutoff -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Uzávěrka', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Uzávěrka aktivní', 'duj-wellness') ?></label>
                        <div><label><input type="checkbox" name="cutoff_enabled" value="1" <?= checked($s->cutoffEnabled(), true, false) ?>> <?= esc_html__('Aktivovat uzávěrku stejný den', 'duj-wellness') ?></label></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Čas uzávěrky', 'duj-wellness') ?></label>
                        <div><input type="time" name="cutoff_time" value="<?= esc_attr($s->cutoffTime()) ?>"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Min. lead time (min)', 'duj-wellness') ?></label>
                        <div>
                            <input type="number" name="min_lead_time_minutes" value="<?= esc_attr((string)$s->minLeadTimeMinutes()) ?>" min="0">
                            <p class="description"><?= esc_html__('Minimum minut od teď do začátku slotu.', 'duj-wellness') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Ubytování -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Ubytování', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Výchozí politika pro obsazené dny', 'duj-wellness') ?></label>
                        <div>
                            <select name="default_accommodation_policy">
                                <option value="ignore"      <?= selected($s->defaultAccommodationPolicy(), 'ignore',      false) ?>><?= esc_html__('Ignorovat', 'duj-wellness') ?></option>
                                <option value="guests_only" <?= selected($s->defaultAccommodationPolicy(), 'guests_only', false) ?>><?= esc_html__('Jen ubytovaní', 'duj-wellness') ?></option>
                                <option value="closed"      <?= selected($s->defaultAccommodationPolicy(), 'closed',      false) ?>><?= esc_html__('Zavřeno', 'duj-wellness') ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Data stará po (dnech)', 'duj-wellness') ?></label>
                        <div><input type="number" name="accommodation_stale_after_days" value="<?= esc_attr((string)$s->accommodationStaleAfterDays()) ?>" min="1" max="30"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Politika při zastaralých datech', 'duj-wellness') ?></label>
                        <div>
                            <select name="stale_policy">
                                <option value="fail_safe" <?= selected($s->stalePolicy(), 'fail_safe', false) ?>><?= esc_html__('Bezpečné selhání (closed)', 'duj-wellness') ?></option>
                                <option value="warn_only" <?= selected($s->stalePolicy(), 'warn_only', false) ?>><?= esc_html__('Jen varovat', 'duj-wellness') ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Bankovní údaje -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Bankovní převod / QR', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('IBAN', 'duj-wellness') ?></label>
                        <div><input type="text" name="bank_account_iban" value="<?= esc_attr($s->bankAccountIban()) ?>" placeholder="CZ…"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Číslo účtu (CZ formát)', 'duj-wellness') ?></label>
                        <div><input type="text" name="bank_account_number" value="<?= esc_attr($s->bankAccountNumber()) ?>" placeholder="123456789/0100"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('QR hold (h)', 'duj-wellness') ?></label>
                        <div><input type="number" name="qr_bank_hold_hours" value="<?= esc_attr((string)$s->qrBankHoldHours()) ?>" min="1" max="168"></div>
                    </div>
                </div>

                <!-- Kontakt / texty -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Kontakt a texty', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Kontaktní e-mail', 'duj-wellness') ?></label>
                        <div><input type="email" name="contact_email" value="<?= esc_attr($s->contactEmail()) ?>"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Kontaktní telefon', 'duj-wellness') ?></label>
                        <div><input type="text" name="contact_phone" value="<?= esc_attr($s->contactPhone()) ?>"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Adresa', 'duj-wellness') ?></label>
                        <div><input type="text" name="address" value="<?= esc_attr($s->address()) ?>"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('URL obchodních podmínek', 'duj-wellness') ?></label>
                        <div><input type="url" name="vop_url" value="<?= esc_attr($s->vopUrl()) ?>"></div>
                    </div>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Text pro vyhrazené dny', 'duj-wellness') ?></label>
                        <div><textarea name="guests_only_message" rows="2"><?= esc_textarea($s->guestsOnlyMessage()) ?></textarea></div>
                    </div>
                </div>

                <!-- GDPR -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('GDPR', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Retence osobních dat (měsíce)', 'duj-wellness') ?></label>
                        <div><input type="number" name="gdpr_retention_months" value="<?= esc_attr((string)$s->gdprRetentionMonths()) ?>" min="1" max="120"></div>
                    </div>
                </div>

                <!-- Ladění -->
                <div class="duj-settings-section">
                    <h3><?= esc_html__('Ladění', 'duj-wellness') ?></h3>
                    <div class="duj-settings-row">
                        <label><?= esc_html__('Debug mód', 'duj-wellness') ?></label>
                        <div><label><input type="checkbox" name="debug_mode" value="1" <?= checked($s->debugMode(), true, false) ?>> <?= esc_html__('Zapnout ladicí výstup', 'duj-wellness') ?></label></div>
                    </div>
                </div>

                <p><button type="submit" class="button button-primary button-large"><?= esc_html__('Uložit nastavení', 'duj-wellness') ?></button></p>
            </form>
        </div>
        <script>document.body.dataset.dujPage = 'settings';</script>
        <?php
    }
}

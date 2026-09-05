<?php

declare(strict_types=1);

namespace Duj\Wellness\Migrations;

/**
 * Vytvoří všechny tabulky pluginu a vloží výchozí seed data.
 *
 * POZOR: dbDelta() neumí spolehlivě UNIQUE indexy na nullable sloupcích.
 * Proto UNIQUE indexy přidáváme explicitním ALTER TABLE.
 */
final class Migration001Initial implements MigrationInterface
{
    public function version(): int
    {
        return 1;
    }

    public function up(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $this->createTables($wpdb, $charset);
        $this->addUniqueIndexes($wpdb);
        $this->seedResources($wpdb);
        $this->seedScheduleRules($wpdb);
        $this->seedPriceTiers($wpdb);
        $this->seedPrices($wpdb);
        $this->seedAccessCodes($wpdb);
        $this->seedEmailTemplates($wpdb);
    }

    private function createTables(\wpdb $wpdb, string $charset): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $p = $wpdb->prefix;
        $now = current_time('mysql', true); // UTC

        $tables = [];

        // ── duj_resources ────────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_resources (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug         VARCHAR(50)  NOT NULL,
            name         VARCHAR(120) NOT NULL,
            description  TEXT NULL,
            capacity     SMALLINT UNSIGNED NOT NULL DEFAULT 6,
            sort_order   SMALLINT NOT NULL DEFAULT 0,
            is_active    TINYINT(1) NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NOT NULL,
            UNIQUE KEY uq_slug (slug)
        ) $charset;";

        // ── duj_schedule_rules ───────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_schedule_rules (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label          VARCHAR(120) NULL,
            weekday        TINYINT UNSIGNED NOT NULL,
            time_from      TIME NOT NULL,
            time_to        TIME NOT NULL,
            valid_from     DATE NULL,
            valid_to       DATE NULL,
            resource_scope JSON NULL,
            is_active      TINYINT(1) NOT NULL DEFAULT 1,
            created_at     DATETIME NOT NULL,
            KEY idx_weekday (weekday, is_active)
        ) $charset;";

        // ── duj_schedule_overrides ───────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_schedule_overrides (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            override_date DATE NOT NULL,
            mode          ENUM('closed','replace') NOT NULL,
            slots         JSON NULL,
            note          VARCHAR(255) NULL,
            created_by    BIGINT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            UNIQUE KEY uq_date (override_date)
        ) $charset;";

        // ── duj_price_tiers ──────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_price_tiers (
            id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug             VARCHAR(40) NOT NULL,
            label            VARCHAR(120) NOT NULL,
            is_default       TINYINT(1) NOT NULL DEFAULT 0,
            requires_code    TINYINT(1) NOT NULL DEFAULT 0,
            show_in_form     TINYINT(1) NOT NULL DEFAULT 1,
            cutoff_mode      ENUM('inherit','lead_time_only','none') NOT NULL DEFAULT 'inherit',
            min_lead_minutes INT UNSIGNED NULL,
            sort_order       SMALLINT NOT NULL DEFAULT 0,
            is_active        TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_slug (slug)
        ) $charset;";

        // ── duj_prices ───────────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_prices (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tier_slug     VARCHAR(40) NOT NULL,
            combo_key     VARCHAR(60) NOT NULL,
            label         VARCHAR(120) NOT NULL,
            amount_minor  INT UNSIGNED NOT NULL,
            currency      CHAR(3) NOT NULL DEFAULT 'CZK',
            weekday       TINYINT UNSIGNED NULL,
            time_from     TIME NULL,
            valid_from    DATE NULL,
            valid_to      DATE NULL,
            priority      SMALLINT NOT NULL DEFAULT 0,
            is_active     TINYINT(1) NOT NULL DEFAULT 1,
            KEY idx_lookup (tier_slug, combo_key, is_active)
        ) $charset;";

        // ── duj_access_codes ─────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_access_codes (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code        VARCHAR(40) NOT NULL,
            tier_slug   VARCHAR(40) NOT NULL,
            label       VARCHAR(160) NULL,
            valid_from  DATE NULL,
            valid_to    DATE NULL,
            max_uses    INT UNSIGNED NULL,
            used_count  INT UNSIGNED NOT NULL DEFAULT 0,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL,
            UNIQUE KEY uq_code (code)
        ) $charset;";

        // ── duj_bookings ─────────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_bookings (
            id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid              CHAR(36) NOT NULL,
            reference         VARCHAR(20) NOT NULL,
            booking_date      DATE NOT NULL,
            slot_from         TIME NOT NULL,
            slot_to           TIME NOT NULL,
            combo_key         VARCHAR(60) NOT NULL,
            guests            SMALLINT UNSIGNED NULL,
            status            VARCHAR(30) NOT NULL,
            tier_slug         VARCHAR(40) NOT NULL DEFAULT 'public',
            access_code       VARCHAR(40) NULL,
            amount_minor      INT UNSIGNED NOT NULL,
            currency          CHAR(3) NOT NULL DEFAULT 'CZK',
            customer_name     VARCHAR(160) NULL,
            customer_email    VARCHAR(190) NOT NULL,
            customer_phone    VARCHAR(40) NOT NULL,
            customer_note     TEXT NULL,
            admin_note        TEXT NULL,
            payment_method    VARCHAR(30) NOT NULL,
            payment_status    VARCHAR(30) NOT NULL,
            payment_provider  VARCHAR(30) NULL,
            payment_intent_id VARCHAR(190) NULL,
            payment_meta      JSON NULL,
            hold_expires_at   DATETIME NULL,
            auth_expires_at   DATETIME NULL,
            confirmed_at      DATETIME NULL,
            confirmed_by      BIGINT UNSIGNED NULL,
            cancelled_at      DATETIME NULL,
            cancel_reason     VARCHAR(255) NULL,
            consent_at        DATETIME NULL,
            consent_ip        VARBINARY(16) NULL,
            source            VARCHAR(30) NOT NULL DEFAULT 'web',
            locale            VARCHAR(10) NOT NULL DEFAULT 'cs_CZ',
            created_at        DATETIME NOT NULL,
            updated_at        DATETIME NOT NULL,
            UNIQUE KEY uq_uuid (uuid),
            UNIQUE KEY uq_reference (reference),
            KEY idx_date (booking_date, slot_from),
            KEY idx_status (status),
            KEY idx_pi (payment_intent_id)
        ) $charset;";

        // ── duj_booking_items ────────────────────────────────────────────────
        // UNIQUE na nullable blocking_key přidáme přes ALTER TABLE níže.
        $tables[] = "CREATE TABLE {$p}duj_booking_items (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id     BIGINT UNSIGNED NOT NULL,
            resource_id    BIGINT UNSIGNED NOT NULL,
            blocking_key   VARCHAR(191) NULL,
            blocked_from   DATETIME NULL,
            blocked_to     DATETIME NULL,
            buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at     DATETIME NOT NULL,
            KEY idx_booking (booking_id),
            KEY idx_overlap (resource_id, blocked_from, blocked_to),
            CONSTRAINT fk_bi_booking FOREIGN KEY (booking_id)
                REFERENCES {$p}duj_bookings(id) ON DELETE CASCADE
        ) $charset;";

        // ── duj_day_locks ────────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_day_locks (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lock_date   DATE NOT NULL,
            resource_id BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY uq_day_resource (lock_date, resource_id)
        ) $charset;";

        // ── duj_action_tokens ────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_action_tokens (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            action     VARCHAR(30) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at    DATETIME NULL,
            used_ip    VARBINARY(16) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_token (token_hash),
            KEY idx_booking (booking_id, action)
        ) $charset;";

        // ── duj_email_templates ──────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_email_templates (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(60) NOT NULL,
            subject      VARCHAR(255) NOT NULL,
            body_html    LONGTEXT NOT NULL,
            is_enabled   TINYINT(1) NOT NULL DEFAULT 1,
            updated_at   DATETIME NOT NULL,
            UNIQUE KEY uq_key (template_key)
        ) $charset;";

        // ── duj_notifications ────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_notifications (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id   BIGINT UNSIGNED NULL,
            channel      VARCHAR(30) NOT NULL,
            template_key VARCHAR(60) NULL,
            recipient    VARCHAR(190) NULL,
            status       VARCHAR(20) NOT NULL,
            error        TEXT NULL,
            created_at   DATETIME NOT NULL,
            KEY idx_booking (booking_id)
        ) $charset;";

        // ── duj_audit_log ────────────────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_audit_log (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NULL,
            user_id    BIGINT UNSIGNED NULL,
            action     VARCHAR(60) NOT NULL,
            data       JSON NULL,
            ip         VARBINARY(16) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_booking (booking_id),
            KEY idx_created (created_at)
        ) $charset;";

        // ── duj_accommodation_blocks ─────────────────────────────────────────
        $tables[] = "CREATE TABLE {$p}duj_accommodation_blocks (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            block_date   DATE NOT NULL,
            policy       ENUM('ignore','guests_only','closed') NOT NULL DEFAULT 'guests_only',
            source       VARCHAR(30) NOT NULL,
            external_ref VARCHAR(190) NULL,
            is_manual    TINYINT(1) NOT NULL DEFAULT 0,
            note         VARCHAR(255) NULL,
            synced_at    DATETIME NULL,
            created_at   DATETIME NOT NULL,
            UNIQUE KEY uq_date (block_date),
            KEY idx_source (source)
        ) $charset;";

        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }

    private function addUniqueIndexes(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_booking_items';

        // Ověř, zda index ještě neexistuje (idempotence)
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM information_schema.STATISTICS
                 WHERE table_schema = %s AND table_name = %s AND index_name = %s",
                DB_NAME,
                $table,
                'uq_blocking'
            )
        );

        if (!$exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_blocking` (`blocking_key`)");
        }
    }

    private function seedResources(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_resources';
        $now = current_time('mysql', true);

        $resources = [
            [
                'slug'        => 'sud',
                'name'        => 'Koupací sud',
                'description' => 'Dřevěný koupací sud pro až 6 osob.',
                'capacity'    => 6,
                'sort_order'  => 1,
            ],
            [
                'slug'        => 'sauna',
                'name'        => 'Sauna',
                'description' => 'Finská sauna pro až 6 osob.',
                'capacity'    => 6,
                'sort_order'  => 2,
            ],
        ];

        foreach ($resources as $r) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $r['slug'])
            );

            if (!$exists) {
                $wpdb->insert($table, array_merge($r, [
                    'is_active'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    private function seedScheduleRules(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_schedule_rules';
        $now = current_time('mysql', true);

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return;
        }

        // Po (1) a St (3): 16:00–18:00 a 19:00–21:00
        $rules = [
            ['weekday' => 1, 'time_from' => '16:00:00', 'time_to' => '18:00:00', 'label' => 'Pondělí odpoledne'],
            ['weekday' => 1, 'time_from' => '19:00:00', 'time_to' => '21:00:00', 'label' => 'Pondělí večer'],
            ['weekday' => 3, 'time_from' => '16:00:00', 'time_to' => '18:00:00', 'label' => 'Středa odpoledne'],
            ['weekday' => 3, 'time_from' => '19:00:00', 'time_to' => '21:00:00', 'label' => 'Středa večer'],
        ];

        foreach ($rules as $rule) {
            $wpdb->insert($table, array_merge($rule, [
                'resource_scope' => null,
                'is_active'      => 1,
                'created_at'     => $now,
            ]));
        }
    }

    private function seedPriceTiers(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_price_tiers';

        $tiers = [
            [
                'slug'             => 'public',
                'label'            => 'Veřejnost',
                'is_default'       => 1,
                'requires_code'    => 0,
                'show_in_form'     => 1,
                'cutoff_mode'      => 'inherit',
                'min_lead_minutes' => null,
                'sort_order'       => 0,
                'is_active'        => 1,
            ],
            [
                'slug'             => 'guest',
                'label'            => 'Ubytovaní hosté',
                'is_default'       => 0,
                'requires_code'    => 1,
                'show_in_form'     => 1,
                'cutoff_mode'      => 'lead_time_only',
                'min_lead_minutes' => null,
                'sort_order'       => 1,
                'is_active'        => 1,
            ],
        ];

        foreach ($tiers as $tier) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $tier['slug'])
            );

            if (!$exists) {
                $wpdb->insert($table, $tier);
            }
        }
    }

    private function seedPrices(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_prices';

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return;
        }

        // Haléře: 1 500 Kč = 150 000, 2 000 Kč = 200 000, 1 000 Kč = 100 000, 1 500 Kč = 150 000
        $prices = [
            // veřejnost
            ['tier_slug' => 'public', 'combo_key' => 'sud',       'label' => 'Koupací sud',           'amount_minor' => 150000],
            ['tier_slug' => 'public', 'combo_key' => 'sauna',     'label' => 'Sauna',                  'amount_minor' => 150000],
            ['tier_slug' => 'public', 'combo_key' => 'sauna+sud', 'label' => 'Sauna i sud (kombo)',    'amount_minor' => 200000],
            // ubytovaní
            ['tier_slug' => 'guest',  'combo_key' => 'sud',       'label' => 'Koupací sud (host)',     'amount_minor' => 100000],
            ['tier_slug' => 'guest',  'combo_key' => 'sauna',     'label' => 'Sauna (host)',            'amount_minor' => 100000],
            ['tier_slug' => 'guest',  'combo_key' => 'sauna+sud', 'label' => 'Sauna i sud — kombo (host)', 'amount_minor' => 150000],
        ];

        foreach ($prices as $price) {
            $wpdb->insert($table, array_merge($price, [
                'currency'   => 'CZK',
                'weekday'    => null,
                'time_from'  => null,
                'valid_from' => null,
                'valid_to'   => null,
                'priority'   => 0,
                'is_active'  => 1,
            ]));
        }
    }

    private function seedAccessCodes(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_access_codes';
        $now = current_time('mysql', true);

        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE code = %s", 'HOSTE2026')
        );

        if (!$exists) {
            $wpdb->insert($table, [
                'code'       => 'HOSTE2026',
                'tier_slug'  => 'guest',
                'label'      => 'Výchozí kód pro ubytované — změňte v nastavení',
                'valid_from' => null,
                'valid_to'   => null,
                'max_uses'   => null,
                'used_count' => 0,
                'is_active'  => 1,
                'created_at' => $now,
            ]);
        }
    }

    private function seedEmailTemplates(\wpdb $wpdb): void
    {
        $p = $wpdb->prefix;
        $table = $p . 'duj_email_templates';
        $now = current_time('mysql', true);

        $templates = $this->defaultEmailTemplates();

        foreach ($templates as $tpl) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `{$table}` WHERE template_key = %s", $tpl['template_key'])
            );

            if (!$exists) {
                $wpdb->insert($table, array_merge($tpl, ['is_enabled' => 1, 'updated_at' => $now]));
            }
        }
    }

    /** @return array<int, array{template_key: string, subject: string, body_html: string}> */
    private function defaultEmailTemplates(): array
    {
        return [
            [
                'template_key' => 'customer_booking_received',
                'subject'      => 'Přijali jsme vaši rezervaci wellness ({{reference}})',
                'body_html'    => '<p>Dobrý den, {{customer_name}},</p>
<p>děkujeme za rezervaci wellness v Domečku u Josefa.</p>
<p><strong>{{weekday}} {{date}}, {{time_from}}–{{time_to}}</strong> · {{service_label}} · {{price}}</p>
<p>Rezervaci ještě potvrdíme — dáme vám vědět e-mailem, obvykle do 24 hodin.
Částka je zatím na vaší kartě pouze blokovaná, stržena bude až po potvrzení.</p>
<p>Rezervaci můžete zrušit zde: <a href="{{cancel_url}}">{{cancel_url}}</a></p>
<p>Leona a Míra, {{contact_phone}}</p>',
            ],
            [
                'template_key' => 'admin_booking_new',
                'subject'      => 'NOVÁ REZERVACE {{reference}} — {{date}} {{time_from}}',
                'body_html'    => '<p>{{service_label}} · {{weekday}} {{date}} {{time_from}}–{{time_to}} · {{guests}} osob · {{price}}</p>
<p>{{customer_name}} · {{customer_email}} · {{customer_phone}}</p>
<p>Poznámka: {{customer_note}}</p>
<p>
  <a href="{{confirm_url}}" style="background:#22c55e;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;margin-right:8px">POTVRDIT</a>
  <a href="{{reject_url}}" style="background:#ef4444;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px">ZAMÍTNOUT</a>
</p>
<p>Detail v administraci: <a href="{{admin_url}}">{{admin_url}}</a></p>',
            ],
            [
                'template_key' => 'customer_booking_confirmed',
                'subject'      => 'Rezervace wellness potvrzena — {{date}} {{time_from}}',
                'body_html'    => '<p>Vaše rezervace je potvrzená. Těšíme se na vás!</p>
<p><strong>{{weekday}} {{date}}, {{time_from}}–{{time_to}}</strong> · {{service_label}} · {{price}}</p>
<p>Adresa: {{address}}. Zatopení, úklid a ručníky jsou v ceně.</p>
<p><em>(V příloze najdete soubor pro přidání do kalendáře.)</em></p>',
            ],
            [
                'template_key' => 'customer_booking_rejected',
                'subject'      => 'Rezervace wellness {{reference}} — bohužel nemůžeme potvrdit',
                'body_html'    => '<p>Mrzí nás to, ale termín {{date}} {{time_from}}–{{time_to}} nemůžeme potvrdit.</p>
<p>Blokace částky na vaší kartě byla zrušena, nic vám nebylo strženo.</p>
<p>Rádi vám nabídneme jiný termín — ozvěte se na {{contact_phone}} nebo <a href="mailto:{{contact_email}}">{{contact_email}}</a>.</p>',
            ],
            [
                'template_key' => 'customer_payment_instructions',
                'subject'      => 'Platební instrukce pro rezervaci {{reference}}',
                'body_html'    => '<p>Dobrý den, {{customer_name}},</p>
<p>pro dokončení rezervace wellness prosím proveďte platbu převodem:</p>
<p>Částka: <strong>{{price}}</strong><br>
Variabilní symbol: <strong>{{reference}}</strong><br>
IBAN: {{bank_iban}}</p>
<p>{{qr_payment_image}}</p>
<p>Termín je vyhrazen do uhrazení platby (maximálně 48 hodin od rezervace).</p>',
            ],
            [
                'template_key' => 'customer_booking_cancelled',
                'subject'      => 'Rezervace wellness {{reference}} byla zrušena',
                'body_html'    => '<p>Dobrý den, {{customer_name}},</p>
<p>vaše rezervace <strong>{{reference}}</strong> ({{date}} {{time_from}}–{{time_to}}) byla zrušena.</p>
<p>V případě dotazů nás kontaktujte na {{contact_phone}}.</p>',
            ],
            [
                'template_key' => 'customer_reminder',
                'subject'      => 'Připomínka: zítra vás čeká wellness v {{time_from}}',
                'body_html'    => '<p>Dobrý den, {{customer_name}},</p>
<p>připomínáme, že zítra <strong>({{date}} v {{time_from}})</strong> vás čeká {{service_label}} v Domečku u Josefa.</p>
<p>Adresa: {{address}}</p>
<p>Těšíme se na vás!</p>',
            ],
            [
                'template_key' => 'admin_auth_expiring',
                'subject'      => 'UPOZORNĚNÍ: autorizace rezervace {{reference}} expiruje za 24 h',
                'body_html'    => '<p>Autorizace platby pro rezervaci <strong>{{reference}}</strong> expiruje za 24 hodin.</p>
<p>Rezervaci je nutné potvrdit nebo zamítnout nejpozději do {{auth_expires_at}}.</p>
<p><a href="{{admin_url}}">Přejít do administrace</a></p>',
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Migrations;

/**
 * Doosadí výchozí rozvrh a ceník, pokud jsou tabulky prázdné.
 * Nutné pro instalace, kde migrace v1 proběhla před přidáním seed dat.
 */
final class Migration003ReseedDefaults implements MigrationInterface
{
    public function version(): int
    {
        return 3;
    }

    public function up(): void
    {
        global $wpdb;
        $now = current_time('mysql', true);

        $this->seedResources($wpdb, $now);
        $this->seedScheduleRules($wpdb, $now);
        $this->seedPriceTiers($wpdb);
        $this->seedPrices($wpdb);
        $this->seedAccessCode($wpdb, $now);
    }

    private function seedResources(\wpdb $wpdb, string $now): void
    {
        $table = $wpdb->prefix . 'duj_resources';
        foreach ([
            ['slug' => 'sud',   'name' => 'Koupací sud', 'description' => 'Dřevěný koupací sud pro až 6 osob.', 'capacity' => 6, 'sort_order' => 1],
            ['slug' => 'sauna', 'name' => 'Sauna',        'description' => 'Finská sauna pro až 6 osob.',        'capacity' => 6, 'sort_order' => 2],
        ] as $r) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $r['slug']));
            if (!$exists) {
                $wpdb->insert($table, array_merge($r, ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));
            }
        }
    }

    private function seedScheduleRules(\wpdb $wpdb, string $now): void
    {
        $table = $wpdb->prefix . 'duj_schedule_rules';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return;
        }

        foreach ([
            ['weekday' => 1, 'time_from' => '16:00:00', 'time_to' => '18:00:00', 'label' => 'Pondělí odpoledne'],
            ['weekday' => 1, 'time_from' => '19:00:00', 'time_to' => '21:00:00', 'label' => 'Pondělí večer'],
            ['weekday' => 3, 'time_from' => '16:00:00', 'time_to' => '18:00:00', 'label' => 'Středa odpoledne'],
            ['weekday' => 3, 'time_from' => '19:00:00', 'time_to' => '21:00:00', 'label' => 'Středa večer'],
        ] as $rule) {
            $wpdb->insert($table, array_merge($rule, ['resource_scope' => null, 'is_active' => 1, 'created_at' => $now]));
        }
    }

    private function seedPriceTiers(\wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'duj_price_tiers';
        foreach ([
            ['slug' => 'public', 'label' => 'Veřejnost',        'is_default' => 1, 'requires_code' => 0, 'show_in_form' => 1, 'cutoff_mode' => 'inherit',       'min_lead_minutes' => null, 'sort_order' => 0, 'is_active' => 1],
            ['slug' => 'guest',  'label' => 'Ubytovaní hosté',  'is_default' => 0, 'requires_code' => 1, 'show_in_form' => 1, 'cutoff_mode' => 'lead_time_only', 'min_lead_minutes' => null, 'sort_order' => 1, 'is_active' => 1],
        ] as $tier) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $tier['slug']));
            if (!$exists) {
                $wpdb->insert($table, $tier);
            }
        }
    }

    private function seedPrices(\wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'duj_prices';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 0) {
            return;
        }

        foreach ([
            ['tier_slug' => 'public', 'combo_key' => 'sud',       'label' => 'Koupací sud',                   'amount_minor' => 150000],
            ['tier_slug' => 'public', 'combo_key' => 'sauna',     'label' => 'Sauna',                          'amount_minor' => 150000],
            ['tier_slug' => 'public', 'combo_key' => 'sauna+sud', 'label' => 'Sauna i sud (kombo)',            'amount_minor' => 200000],
            ['tier_slug' => 'guest',  'combo_key' => 'sud',       'label' => 'Koupací sud (host)',             'amount_minor' => 100000],
            ['tier_slug' => 'guest',  'combo_key' => 'sauna',     'label' => 'Sauna (host)',                   'amount_minor' => 100000],
            ['tier_slug' => 'guest',  'combo_key' => 'sauna+sud', 'label' => 'Sauna i sud — kombo (host)',    'amount_minor' => 150000],
        ] as $price) {
            $wpdb->insert($table, array_merge($price, [
                'currency' => 'CZK', 'weekday' => null, 'time_from' => null,
                'valid_from' => null, 'valid_to' => null, 'priority' => 0, 'is_active' => 1,
            ]));
        }
    }

    private function seedAccessCode(\wpdb $wpdb, string $now): void
    {
        $table = $wpdb->prefix . 'duj_access_codes';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE code = %s", 'HOSTE2026'));
        if (!$exists) {
            $wpdb->insert($table, [
                'code'       => 'HOSTE2026',
                'tier_slug'  => 'guest',
                'label'      => 'Výchozí kód pro ubytované — změňte v nastavení',
                'valid_from' => null, 'valid_to' => null, 'max_uses' => null,
                'used_count' => 0, 'is_active' => 1, 'created_at' => $now,
            ]);
        }
    }
}

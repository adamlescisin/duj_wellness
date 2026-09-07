<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

/**
 * Admin REST endpointy pro správu cen a přístupových kódů.
 */
final class AdminPricingController
{
    public function register(): void
    {
        $cb = fn($m, $h) => ['methods' => $m, 'callback' => [$this, $h], 'permission_callback' => [$this, 'can']];

        register_rest_route('duj/v1', '/admin/price-tiers', [
            $cb('GET',  'listTiers'),
            $cb('POST', 'createTier'),
        ]);
        register_rest_route('duj/v1', '/admin/price-tiers/bulk', [
            $cb('POST', 'saveTiersBulk'),
        ]);
        register_rest_route('duj/v1', '/admin/price-tiers/(?P<id>\d+)', [
            $cb('DELETE', 'deleteTier'),
        ]);
        register_rest_route('duj/v1', '/admin/prices/bulk', [
            $cb('POST', 'savePricesBulk'),
        ]);
        register_rest_route('duj/v1', '/admin/access-codes', [
            $cb('GET',  'listCodes'),
            $cb('POST', 'createCode'),
        ]);
        register_rest_route('duj/v1', '/admin/access-codes/(?P<id>\d+)', [
            $cb('DELETE', 'deleteCode'),
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function listTiers(): \WP_REST_Response
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM `{$wpdb->prefix}duj_price_tiers` ORDER BY sort_order ASC",
            ARRAY_A
        ) ?? [];
        return new \WP_REST_Response($rows);
    }

    public function createTier(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body  = $req->get_json_params();
        $slug  = sanitize_key($body['slug'] ?? '');
        $label = sanitize_text_field($body['label'] ?? '');

        if ($slug === '' || $label === '') {
            return new \WP_Error('missing_fields', 'Chybí slug nebo label.', ['status' => 400]);
        }

        $cutoffMode = sanitize_key($body['cutoff_mode'] ?? 'inherit');
        if (!in_array($cutoffMode, ['inherit', 'lead_time_only', 'none'], true)) {
            $cutoffMode = 'inherit';
        }

        $table = $wpdb->prefix . 'duj_price_tiers';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $slug));
        if ($exists) {
            return new \WP_Error('slug_exists', 'Hladina s tímto slugem již existuje.', ['status' => 409]);
        }

        $wpdb->insert($table, [
            'slug'          => $slug,
            'label'         => $label,
            'is_default'    => 0,
            'requires_code' => (int) !empty($body['requires_code']),
            'show_in_form'  => isset($body['show_in_form']) ? (int) $body['show_in_form'] : 1,
            'cutoff_mode'   => $cutoffMode,
            'sort_order'    => max(0, (int) ($body['sort_order'] ?? 10)),
            'is_active'     => 1,
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id, 'slug' => $slug], 201);
    }

    public function saveTiersBulk(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body    = $req->get_json_params();
        $tiers   = (array) ($body['tiers'] ?? []);
        $table   = $wpdb->prefix . 'duj_price_tiers';
        $updated = 0;

        foreach ($tiers as $t) {
            $id    = (int) ($t['id'] ?? 0);
            $label = sanitize_text_field($t['label'] ?? '');
            if ($id <= 0 || $label === '') {
                continue;
            }

            $cutoffMode = sanitize_key($t['cutoff_mode'] ?? 'inherit');
            if (!in_array($cutoffMode, ['inherit', 'lead_time_only', 'none'], true)) {
                $cutoffMode = 'inherit';
            }

            $wpdb->update($table, [
                'label'         => $label,
                'requires_code' => (int) !empty($t['requires_code']),
                'show_in_form'  => (int) !empty($t['show_in_form']),
                'is_active'     => (int) !empty($t['is_active']),
                'cutoff_mode'   => $cutoffMode,
                'sort_order'    => max(0, (int) ($t['sort_order'] ?? 0)),
            ], ['id' => $id]);

            $updated++;
        }

        return new \WP_REST_Response(['updated' => $updated]);
    }

    public function deleteTier(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'duj_price_tiers',
            ['is_active' => 0],
            ['id' => (int) $req['id']]
        );
        return new \WP_REST_Response(['deactivated' => true]);
    }

    public function savePricesBulk(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body   = $req->get_json_params();
        $prices = (array)($body['prices'] ?? []);
        $table  = $wpdb->prefix . 'duj_prices';

        $updated = 0;
        foreach ($prices as $p) {
            $id     = (int)($p['id'] ?? 0);
            $amount = (int)($p['amount_minor'] ?? 0);
            if ($id <= 0 || $amount < 0) continue;

            $wpdb->update($table, ['amount_minor' => $amount], ['id' => $id]);
            $updated++;
        }

        return new \WP_REST_Response(['updated' => $updated]);
    }

    public function listCodes(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_access_codes';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE is_active = 1 AND (valid_to IS NULL OR valid_to >= %s) ORDER BY created_at DESC LIMIT 100",
                date('Y-m-d')
            ),
            ARRAY_A
        ) ?? [];
        return new \WP_REST_Response($rows);
    }

    public function createCode(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body     = $req->get_json_params();
        $tierSlug = sanitize_key($body['tier_slug'] ?? '');
        $label    = sanitize_text_field($body['label'] ?? '');

        if ($tierSlug === '' || $label === '') {
            return new \WP_Error('missing_fields', 'Chybí tier_slug nebo label.', ['status' => 400]);
        }

        // Generuj unikátní kód: 8 náhodných alfanumerických znaků (uppercase)
        $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));

        $table    = $wpdb->prefix . 'duj_access_codes';
        $validFrom = !empty($body['valid_from']) ? sanitize_text_field($body['valid_from']) : null;
        $validTo   = !empty($body['valid_to'])   ? sanitize_text_field($body['valid_to'])   : null;
        $maxUses   = isset($body['max_uses']) && $body['max_uses'] !== null ? max(1, (int)$body['max_uses']) : null;

        $wpdb->insert($table, [
            'code'       => $code,
            'tier_slug'  => $tierSlug,
            'label'      => $label,
            'valid_from' => $validFrom,
            'valid_to'   => $validTo,
            'max_uses'   => $maxUses,
            'used_count' => 0,
            'is_active'  => 1,
        ]);

        return new \WP_REST_Response(['code' => $code, 'id' => $wpdb->insert_id], 201);
    }

    public function deleteCode(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'duj_access_codes',
            ['is_active' => 0],
            ['id' => (int) $req['id']]
        );
        return new \WP_REST_Response(['deactivated' => true]);
    }
}

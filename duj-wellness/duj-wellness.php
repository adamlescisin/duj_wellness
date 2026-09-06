<?php
/**
 * Plugin Name:       Duj Wellness — Rezervační systém
 * Plugin URI:        https://domecekujosefa.cz
 * Description:       Rezervace koupacího sudu a sauny s online platbou přes Stripe.
 * Version:           0.2.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Domeček u Josefa
 * Author URI:        https://domecekujosefa.cz
 * Text Domain:       duj-wellness
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('DUJ_WELLNESS_VERSION', '0.2.1');
define('DUJ_WELLNESS_FILE', __FILE__);
define('DUJ_WELLNESS_DIR', plugin_dir_path(__FILE__));
define('DUJ_WELLNESS_URL', plugin_dir_url(__FILE__));
define('DUJ_WELLNESS_BASENAME', plugin_basename(__FILE__));

// Self-healing OPcache flush — runs from this file which is always loaded fresh.
// On the first request after each version bump, invalidates every plugin PHP file
// so OPcache doesn't serve stale bytecode when validate_timestamps=0 on the server.
(static function (): void {
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    $stamp = plugin_dir_path(__FILE__) . '.opcache-version';
    if (@file_get_contents($stamp) === DUJ_WELLNESS_VERSION) {
        return;
    }
    $dir = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(plugin_dir_path(__FILE__), \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($dir as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            opcache_invalidate($f->getPathname(), true);
        }
    }
    @file_put_contents($stamp, DUJ_WELLNESS_VERSION);
})();

// PSR-4 autoloader pro vlastní namespace — funguje i bez Composeru.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Duj\\Wellness\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = DUJ_WELLNESS_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Composer autoloader (třetí strany — Stripe, QR-Code, Action Scheduler).
// Nepovinný pro Fázi 0, vyžadovaný od Fáze 3.
if (file_exists(DUJ_WELLNESS_DIR . 'vendor/autoload.php')) {
    require_once DUJ_WELLNESS_DIR . 'vendor/autoload.php';
}

use Duj\Wellness\Activator;
use Duj\Wellness\Deactivator;
use Duj\Wellness\Plugin;

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

// Inline REST handlers — live in this file so they survive partial deploys.
add_filter('rest_pre_dispatch', static function ($result, $server, \WP_REST_Request $request) {
    $route = $request->get_route();

    // Keep PHP alive through the full deploy even after GitHub disconnects.
    if ($route === '/duj/v1/deploy') {
        ignore_user_abort(true);
        set_time_limit(300);
        return $result;
    }

    // Access-code validation: inline implementation so it works even when
    // includes/Domain/TierResolver.php on disk is stale or broken.
    if ($route === '/duj/v1/access-codes/validate' && $request->get_method() === 'GET') {
        global $wpdb;
        $code = sanitize_text_field((string) ($request->get_param('code') ?? ''));
        if ($code === '') {
            return new \WP_REST_Response(['valid' => false, 'message' => 'Kód neplatí.'], 200);
        }
        $date  = sanitize_text_field((string) ($request->get_param('date') ?? gmdate('Y-m-d')));
        $table = $wpdb->prefix . 'duj_access_codes';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tier_slug FROM `{$table}`
                 WHERE code = %s AND is_active = 1
                   AND (valid_from IS NULL OR valid_from <= %s)
                   AND (valid_to   IS NULL OR valid_to   >= %s)
                   AND (max_uses   IS NULL OR used_count < max_uses)
                 LIMIT 1",
                strtoupper($code),
                $date,
                $date
            ),
            ARRAY_A
        );
        if (!$row) {
            return new \WP_REST_Response(['valid' => false, 'message' => 'Kód neplatí.'], 200);
        }
        return new \WP_REST_Response([
            'valid'      => true,
            'tier'       => (string) $row['tier_slug'],
            'valid_code' => strtoupper($code),
        ], 200);
    }

    return $result;
}, 10, 3);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});

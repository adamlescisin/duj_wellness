<?php
/**
 * Plugin Name:       Duj Wellness — Rezervační systém
 * Plugin URI:        https://domecekujosefa.cz
 * Description:       Rezervace koupacího sudu a sauny s online platbou přes Stripe.
 * Version:           0.1.0
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

define('DUJ_WELLNESS_VERSION', '0.1.0');
define('DUJ_WELLNESS_FILE', __FILE__);
define('DUJ_WELLNESS_DIR', plugin_dir_path(__FILE__));
define('DUJ_WELLNESS_URL', plugin_dir_url(__FILE__));
define('DUJ_WELLNESS_BASENAME', plugin_basename(__FILE__));

// Autoloader
if (file_exists(DUJ_WELLNESS_DIR . 'vendor/autoload.php')) {
    require_once DUJ_WELLNESS_DIR . 'vendor/autoload.php';
}

use Duj\Wellness\Activator;
use Duj\Wellness\Deactivator;
use Duj\Wellness\Plugin;

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});

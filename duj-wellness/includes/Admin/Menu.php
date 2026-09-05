<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

use Duj\Wellness\Support\Settings;
use Duj\Wellness\Admin\StatsPage;

/**
 * Registruje admin menu a enqueue admin assetů.
 */
final class Menu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPages(): void
    {
        add_menu_page(
            __('Wellness rezervace', 'duj-wellness'),
            __('Wellness', 'duj-wellness'),
            'duj_manage_bookings',
            'duj-wellness',
            [BookingsPage::class, 'render'],
            'dashicons-heart',
            30
        );

        add_submenu_page('duj-wellness', __('Rezervace', 'duj-wellness'),  __('Rezervace', 'duj-wellness'),  'duj_manage_bookings', 'duj-wellness',             [BookingsPage::class,      'render']);
        add_submenu_page('duj-wellness', __('Kalendář', 'duj-wellness'),   __('Kalendář', 'duj-wellness'),   'duj_manage_bookings', 'duj-wellness-calendar',    [CalendarPage::class,      'render']);
        add_submenu_page('duj-wellness', __('Rozvrh', 'duj-wellness'),     __('Rozvrh', 'duj-wellness'),     'duj_manage_bookings', 'duj-wellness-schedule',    [SchedulePage::class,      'render']);
        add_submenu_page('duj-wellness', __('Ceník', 'duj-wellness'),      __('Ceník', 'duj-wellness'),      'duj_manage_bookings', 'duj-wellness-pricing',     [PricingPage::class,       'render']);
        add_submenu_page('duj-wellness', __('Ubytování', 'duj-wellness'),  __('Ubytování', 'duj-wellness'),  'duj_manage_bookings', 'duj-wellness-accom',       [AccommodationPage::class, 'render']);
        add_submenu_page('duj-wellness', __('E-maily', 'duj-wellness'),    __('E-maily', 'duj-wellness'),    'duj_manage_bookings', 'duj-wellness-emails',      [EmailsPage::class,        'render']);
        add_submenu_page('duj-wellness', __('Notifikace', 'duj-wellness'), __('Notifikace', 'duj-wellness'), 'duj_manage_bookings', 'duj-wellness-notif',       [NotificationsPage::class, 'render']);
        add_submenu_page('duj-wellness', __('Nastavení', 'duj-wellness'),  __('Nastavení', 'duj-wellness'),  'duj_manage_bookings', 'duj-wellness-settings',    [SettingsPage::class,      'render']);
        add_submenu_page('duj-wellness', __('Statistiky', 'duj-wellness'), __('Statistiky', 'duj-wellness'), 'duj_manage_bookings', 'duj-wellness-stats',       [StatsPage::class,         'render']);
    }

    public function enqueueAssets(string $hook): void
    {
        $dujPages = [
            'toplevel_page_duj-wellness',
            'wellness_page_duj-wellness-calendar',
            'wellness_page_duj-wellness-schedule',
            'wellness_page_duj-wellness-pricing',
            'wellness_page_duj-wellness-accom',
            'wellness_page_duj-wellness-emails',
            'wellness_page_duj-wellness-notif',
            'wellness_page_duj-wellness-settings',
            'wellness_page_duj-wellness-stats',
        ];

        if (!in_array($hook, $dujPages, true)) {
            return;
        }

        wp_enqueue_style(
            'duj-wellness-admin',
            DUJ_WELLNESS_URL . 'assets/css/admin.css',
            [],
            DUJ_WELLNESS_VERSION
        );

        wp_register_script(
            'duj-wellness-admin',
            DUJ_WELLNESS_URL . 'assets/js/admin.js',
            [],
            DUJ_WELLNESS_VERSION,
            true
        );
        wp_script_add_data('duj-wellness-admin', 'type', 'module');
        wp_enqueue_script('duj-wellness-admin');

        $settings = Settings::instance();

        wp_localize_script('duj-wellness-admin', 'dujAdmin', [
            'restUrl'       => esc_url_raw(rest_url('duj/v1/')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'newBookingUrl' => admin_url('admin.php?page=duj-wellness&action=new'),
            'stripeMode'    => $settings->stripeMode(),
        ]);
    }
}

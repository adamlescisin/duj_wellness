<?php
/**
 * Spustí se při smazání pluginu z WP admin.
 * Data se smažou jen když je zapnuto 'duj_delete_data_on_uninstall'.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$delete = get_option('duj_delete_data_on_uninstall', false);
if (!$delete) {
    return;
}

global $wpdb;

$tables = [
    'duj_audit_log',
    'duj_notifications',
    'duj_email_templates',
    'duj_action_tokens',
    'duj_day_locks',
    'duj_booking_items',
    'duj_bookings',
    'duj_accommodation_blocks',
    'duj_access_codes',
    'duj_prices',
    'duj_price_tiers',
    'duj_schedule_overrides',
    'duj_schedule_rules',
    'duj_resources',
];

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`");
}

delete_option('duj_db_version');
delete_option('duj_settings');
delete_option('duj_delete_data_on_uninstall');

$role = get_role('duj_wellness_manager');
if ($role) {
    remove_role('duj_wellness_manager');
}

$admins = get_users(['role' => 'administrator']);
foreach ($admins as $admin) {
    $admin->remove_cap('duj_manage_bookings');
}

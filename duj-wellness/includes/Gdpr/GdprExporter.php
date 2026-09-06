<?php

declare(strict_types=1);

namespace Duj\Wellness\Gdpr;

/**
 * Exportér osobních dat pro WP Privacy Tools.
 *
 * Registruje se přes filtr wp_privacy_personal_data_exporters.
 * Vrací všechny rezervace zákazníka identifikované e-mailem.
 */
final class GdprExporter
{
    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
    }

    public function registerExporter(array $exporters): array
    {
        $exporters['duj-wellness'] = [
            'exporter_friendly_name' => __('Wellness rezervace', 'duj-wellness'),
            'callback'               => [$this, 'export'],
        ];
        return $exporters;
    }

    /**
     * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        global $wpdb;

        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $table   = $wpdb->prefix . 'duj_bookings';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, reference, booking_date, slot_from, slot_to, combo_key, guests,
                        status, amount_minor, currency, customer_name, customer_email,
                        customer_phone, customer_note, payment_method, payment_status,
                        created_at
                 FROM `{$table}`
                 WHERE customer_email = %s
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $email, $perPage, $offset
            ),
            ARRAY_A
        ) ?? [];

        $exportItems = [];
        foreach ($rows as $row) {
            $data = [
                ['name' => __('Reference', 'duj-wellness'),       'value' => $row['reference']],
                ['name' => __('Datum rezervace', 'duj-wellness'),  'value' => $row['booking_date']],
                ['name' => __('Čas od', 'duj-wellness'),          'value' => $row['slot_from']],
                ['name' => __('Čas do', 'duj-wellness'),          'value' => $row['slot_to']],
                ['name' => __('Služba', 'duj-wellness'),           'value' => $row['combo_key']],
                ['name' => __('Počet osob', 'duj-wellness'),       'value' => (string)($row['guests'] ?? '')],
                ['name' => __('Stav', 'duj-wellness'),             'value' => $row['status']],
                ['name' => __('Částka', 'duj-wellness'),           'value' => number_format((int)$row['amount_minor'] / 100, 0, ',', ' ') . ' ' . $row['currency']],
                ['name' => __('Jméno zákazníka', 'duj-wellness'),  'value' => (string)($row['customer_name'] ?? '')],
                ['name' => __('E-mail', 'duj-wellness'),           'value' => $row['customer_email']],
                ['name' => __('Telefon', 'duj-wellness'),          'value' => $row['customer_phone']],
                ['name' => __('Poznámka zákazníka', 'duj-wellness'), 'value' => (string)($row['customer_note'] ?? '')],
                ['name' => __('Způsob platby', 'duj-wellness'),    'value' => $row['payment_method']],
                ['name' => __('Stav platby', 'duj-wellness'),      'value' => $row['payment_status']],
                ['name' => __('Vytvořeno', 'duj-wellness'),        'value' => $row['created_at']],
            ];

            $exportItems[] = [
                'group_id'    => 'duj-wellness-bookings',
                'group_label' => __('Wellness rezervace', 'duj-wellness'),
                'item_id'     => 'booking-' . $row['id'],
                'data'        => $data,
            ];
        }

        return [
            'data' => $exportItems,
            'done' => count($rows) < $perPage,
        ];
    }
}

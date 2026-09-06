<?php

declare(strict_types=1);

namespace Duj\Wellness\Admin;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table pro přehled rezervací.
 */
final class BookingsListTable extends \WP_List_Table
{
    private array $items_data = [];
    private int   $total      = 0;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'rezervace',
            'plural'   => 'rezervace',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'cb'         => '<input type="checkbox" />',
            'reference'  => __('Reference', 'duj-wellness'),
            'date'       => __('Datum a čas', 'duj-wellness'),
            'service'    => __('Služba', 'duj-wellness'),
            'customer'   => __('Zákazník', 'duj-wellness'),
            'status'     => __('Stav', 'duj-wellness'),
            'payment'    => __('Platba', 'duj-wellness'),
            'amount'     => __('Částka', 'duj-wellness'),
            'actions'    => __('Akce', 'duj-wellness'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'reference' => ['reference', false],
            'date'      => ['booking_date', true],
            'amount'    => ['amount_minor', false],
        ];
    }

    protected function get_bulk_actions(): array
    {
        return [
            'confirm' => __('Potvrdit', 'duj-wellness'),
            'reject'  => __('Zamítnout', 'duj-wellness'),
            'cancel'  => __('Zrušit', 'duj-wellness'),
        ];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_bookings';

        $per_page     = 20;
        $current_page = $this->get_pagenum();

        $status   = sanitize_text_field($_GET['status']   ?? '');
        $service  = sanitize_text_field($_GET['service']  ?? '');
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to   = sanitize_text_field($_GET['date_to']   ?? '');
        $search    = sanitize_text_field($_GET['s']         ?? '');

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
        if ($service !== '') {
            $where[]  = 'combo_key = %s';
            $params[] = $service;
        }
        if ($date_from !== '') {
            $where[]  = 'booking_date >= %s';
            $params[] = $date_from;
        }
        if ($date_to !== '') {
            $where[]  = 'booking_date <= %s';
            $params[] = $date_to;
        }
        if ($search !== '') {
            $where[]  = '(reference LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s)';
            $like      = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $orderby   = in_array($_GET['orderby'] ?? '', ['reference','booking_date','amount_minor'], true)
            ? sanitize_sql_orderby($_GET['orderby'])
            : 'booking_date';
        $order     = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $offset    = ($current_page - 1) * $per_page;

        if (!empty($params)) {
            $count_sql = $wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", ...$params);
            $rows_sql  = $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                ...[...$params, $per_page, $offset]
            );
        } else {
            $count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
            $rows_sql  = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT {$per_page} OFFSET {$offset}";
        }

        $this->total      = (int) $wpdb->get_var($count_sql);
        $this->items_data = $wpdb->get_results($rows_sql, ARRAY_A) ?? [];

        $this->set_pagination_args([
            'total_items' => $this->total,
            'per_page'    => $per_page,
            'total_pages' => ceil($this->total / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->items = $this->items_data;
    }

    protected function column_cb($item): string
    {
        return '<input type="checkbox" name="booking_ids[]" value="' . (int) $item['id'] . '" />';
    }

    protected function column_reference(array $item): string
    {
        $ref = esc_html($item['reference']);
        return "<a href=\"#\" data-booking-detail=\"{$item['id']}\" class=\"row-title\">{$ref}</a>";
    }

    protected function column_date(array $item): string
    {
        return esc_html($item['booking_date']) . '<br><small>' . esc_html($item['slot_from']) . '–' . esc_html($item['slot_to']) . '</small>';
    }

    protected function column_service(array $item): string
    {
        $labels = ['sud' => 'Koupací sud', 'sauna' => 'Sauna', 'sud+sauna' => 'Sud + sauna'];
        return esc_html($labels[$item['combo_key']] ?? $item['combo_key']);
    }

    protected function column_customer(array $item): string
    {
        return esc_html($item['customer_name'] ?: '—') . '<br><small>' . esc_html($item['customer_email']) . '</small>';
    }

    protected function column_status(array $item): string
    {
        $status = esc_attr($item['status']);
        $labels = [
            'pending_payment'       => 'Čekání na platbu',
            'awaiting_confirmation' => 'Čeká na potvrzení',
            'confirmed'             => 'Potvrzeno',
            'cancelled'             => 'Zrušeno',
            'expired'               => 'Vypršelo',
            'completed'             => 'Dokončeno',
            'rejected'              => 'Zamítnuto',
        ];
        $label = esc_html($labels[$item['status']] ?? $item['status']);
        return "<span class=\"duj-badge duj-badge--{$status}\">{$label}</span>";
    }

    protected function column_payment(array $item): string
    {
        return esc_html($item['payment_method']) . '<br><small>' . esc_html($item['payment_status']) . '</small>';
    }

    protected function column_amount(array $item): string
    {
        return esc_html(number_format((int) $item['amount_minor'] / 100, 0, ',', ' ') . ' Kč');
    }

    protected function column_actions(array $item): string
    {
        $id     = (int) $item['id'];
        $status = $item['status'];
        $out    = "<button type=\"button\" class=\"button button-small\" data-booking-detail=\"{$id}\">" . __('Detail', 'duj-wellness') . '</button> ';

        if ($status === 'awaiting_confirmation') {
            $out .= "<button type=\"button\" class=\"button button-small\" data-booking-action=\"confirm\" data-booking-id=\"{$id}\">" . __('Potvrdit', 'duj-wellness') . '</button> ';
            $out .= "<button type=\"button\" class=\"button button-small\" data-booking-action=\"reject\" data-booking-id=\"{$id}\">" . __('Zamítnout', 'duj-wellness') . '</button> ';
        }
        if (in_array($status, ['pending_payment','awaiting_confirmation','confirmed'], true)) {
            $out .= "<button type=\"button\" class=\"button button-small\" data-booking-action=\"cancel\" data-booking-id=\"{$id}\">" . __('Zrušit', 'duj-wellness') . '</button>';
        }

        return $out;
    }

    public function column_default($item, $column_name): string
    {
        return esc_html($item[$column_name] ?? '—');
    }
}

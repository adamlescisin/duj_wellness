<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

/**
 * Admin REST endpoint pro statistiky.
 */
final class AdminStatsController
{
    public function register(): void
    {
        register_rest_route('duj/v1', '/admin/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get'],
            'permission_callback' => [$this, 'can'],
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function get(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_bookings';

        $period = sanitize_key($req->get_param('period') ?? 'year');
        $months = match ($period) {
            'month'  => 1,
            'quarter' => 3,
            default  => 12,
        };

        $from = date('Y-m-d', strtotime("-{$months} months"));

        // Celkový přehled
        $totals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as cnt, SUM(amount_minor) as revenue
                 FROM `{$table}`
                 WHERE created_at >= %s
                 GROUP BY status",
                $from . ' 00:00:00'
            ),
            ARRAY_A
        ) ?? [];

        $byStatus = [];
        $totalRevenue = 0;
        foreach ($totals as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
            if (in_array($row['status'], ['confirmed', 'awaiting_confirmation'], true)) {
                $totalRevenue += (int) $row['revenue'];
            }
        }

        // Měsíční příjmy (posledních 12 měsíců, bez ohledu na period)
        $monthly = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(booking_date, '%%Y-%%m') as month,
                        COUNT(*) as bookings,
                        SUM(amount_minor) as revenue
                 FROM `{$table}`
                 WHERE booking_date >= %s
                   AND status IN ('confirmed', 'awaiting_confirmation')
                 GROUP BY DATE_FORMAT(booking_date, '%%Y-%%m')
                 ORDER BY month ASC",
                date('Y-m-d', strtotime('-12 months'))
            ),
            ARRAY_A
        ) ?? [];

        // Rezervace podle služby (combo_key)
        $byService = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT combo_key, COUNT(*) as cnt, SUM(amount_minor) as revenue
                 FROM `{$table}`
                 WHERE created_at >= %s
                   AND status IN ('confirmed', 'awaiting_confirmation')
                 GROUP BY combo_key
                 ORDER BY cnt DESC",
                $from . ' 00:00:00'
            ),
            ARRAY_A
        ) ?? [];

        // Top 5 dnů v týdnu
        $byWeekday = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DAYOFWEEK(booking_date) as dow, COUNT(*) as cnt
                 FROM `{$table}`
                 WHERE created_at >= %s
                   AND status IN ('confirmed', 'awaiting_confirmation')
                 GROUP BY dow
                 ORDER BY cnt DESC",
                $from . ' 00:00:00'
            ),
            ARRAY_A
        ) ?? [];

        // Průměrná výše rezervace
        $avg = (int) ($wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(amount_minor) FROM `{$table}` WHERE created_at >= %s AND status IN ('confirmed','awaiting_confirmation')",
                $from . ' 00:00:00'
            )
        ) ?? 0);

        // Počet unikátních zákazníků
        $uniqueCustomers = (int) ($wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT customer_email) FROM `{$table}` WHERE created_at >= %s",
                $from . ' 00:00:00'
            )
        ) ?? 0);

        return new \WP_REST_Response([
            'period'           => $period,
            'from'             => $from,
            'total_revenue'    => $totalRevenue,
            'avg_booking'      => $avg,
            'unique_customers' => $uniqueCustomers,
            'by_status'        => $byStatus,
            'monthly'          => array_map(fn($r) => [
                'month'    => $r['month'],
                'bookings' => (int) $r['bookings'],
                'revenue'  => (int) $r['revenue'],
            ], $monthly),
            'by_service'       => array_map(fn($r) => [
                'combo_key' => $r['combo_key'],
                'bookings'  => (int) $r['cnt'],
                'revenue'   => (int) $r['revenue'],
            ], $byService),
            'by_weekday'       => array_map(fn($r) => [
                'dow' => (int) $r['dow'],
                'cnt' => (int) $r['cnt'],
            ], $byWeekday),
        ]);
    }
}

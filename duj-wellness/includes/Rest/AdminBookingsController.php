<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\BookingService;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Repository\BookingRepository;

/**
 * Admin REST endpointy pro správu rezervací.
 * Všechny vyžadují capability duj_manage_bookings.
 */
final class AdminBookingsController
{
    public function __construct(
        private readonly BookingRepository   $bookingRepo,
        private readonly BookingService      $bookingService,
        private readonly NotificationService $notificationService,
    ) {}

    public function register(): void
    {
        register_rest_route('duj/v1', '/admin/bookings', [
            ['methods' => 'GET',  'callback' => [$this, 'list'],   'permission_callback' => [$this, 'can']],
        ]);

        register_rest_route('duj/v1', '/admin/bookings/bulk', [
            ['methods' => 'POST', 'callback' => [$this, 'bulk'],   'permission_callback' => [$this, 'can']],
        ]);

        register_rest_route('duj/v1', '/admin/bookings/(?P<id>\d+)', [
            ['methods' => 'GET',   'callback' => [$this, 'get'],    'permission_callback' => [$this, 'can']],
            ['methods' => 'PATCH', 'callback' => [$this, 'patch'],  'permission_callback' => [$this, 'can']],
            ['methods' => 'DELETE','callback' => [$this, 'delete'], 'permission_callback' => [$this, 'can']],
        ]);

        register_rest_route('duj/v1', '/admin/bookings/(?P<id>\d+)/action', [
            ['methods' => 'POST', 'callback' => [$this, 'action'],  'permission_callback' => [$this, 'can']],
        ]);

        register_rest_route('duj/v1', '/admin/calendar', [
            ['methods' => 'GET', 'callback' => [$this, 'calendar'], 'permission_callback' => [$this, 'can']],
        ]);

        register_rest_route('duj/v1', '/admin/calendar/day', [
            ['methods' => 'GET', 'callback' => [$this, 'calendarDay'], 'permission_callback' => [$this, 'can']],
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function list(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_bookings';

        $status    = sanitize_text_field($req->get_param('status') ?? '');
        $service   = sanitize_text_field($req->get_param('service') ?? '');
        $date_from = sanitize_text_field($req->get_param('date_from') ?? '');
        $date_to   = sanitize_text_field($req->get_param('date_to') ?? '');
        $search    = sanitize_text_field($req->get_param('s') ?? '');
        $per_page  = min(100, max(1, (int)($req->get_param('per_page') ?? 20)));
        $page      = max(1, (int)($req->get_param('page') ?? 1));
        $offset    = ($page - 1) * $per_page;

        [$where, $params] = $this->buildWhere($status, $service, $date_from, $date_to, $search, $wpdb);

        $sql = empty($params)
            ? $wpdb->prepare("SELECT * FROM `{$table}` WHERE {$where} ORDER BY booking_date DESC LIMIT %d OFFSET %d", $per_page, $offset)
            : $wpdb->prepare("SELECT * FROM `{$table}` WHERE {$where} ORDER BY booking_date DESC LIMIT %d OFFSET %d", ...[...$params, $per_page, $offset]);

        $rows = $wpdb->get_results($sql, ARRAY_A) ?? [];

        return new \WP_REST_Response($rows);
    }

    public function get(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $booking = $this->bookingRepo->findById((int) $req['id']);
        if (!$booking) {
            return new \WP_Error('not_found', 'Rezervace nenalezena.', ['status' => 404]);
        }

        return new \WP_REST_Response([
            'id'             => $booking->id,
            'reference'      => $booking->reference,
            'booking_date'   => $booking->bookingDate,
            'slot_from'      => $booking->slotFrom,
            'slot_to'        => $booking->slotTo,
            'combo_key'      => $booking->comboKey,
            'guests'         => $booking->guests,
            'status'         => $booking->status,
            'tier_slug'      => $booking->tierSlug,
            'amount_minor'   => $booking->amountMinor,
            'currency'       => $booking->currency,
            'customer_name'  => $booking->customerName,
            'customer_email' => $booking->customerEmail,
            'customer_phone' => $booking->customerPhone,
            'customer_note'  => $booking->customerNote,
            'admin_note'     => $booking->adminNote,
            'payment_method' => $booking->paymentMethod,
            'payment_status' => $booking->paymentStatus,
            'source'         => $booking->source,
            'created_at'     => $booking->createdAt,
        ]);
    }

    public function patch(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $booking = $this->bookingRepo->findById((int) $req['id']);
        if (!$booking) {
            return new \WP_Error('not_found', 'Rezervace nenalezena.', ['status' => 404]);
        }

        $body = $req->get_json_params();
        $data = [];

        if (isset($body['admin_note'])) {
            $data['admin_note'] = sanitize_text_field($body['admin_note']);
        }

        if (!empty($data)) {
            $this->bookingRepo->update($booking->id, $data);
        }

        return new \WP_REST_Response(['updated' => true]);
    }

    public function delete(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $booking = $this->bookingRepo->findById((int) $req['id']);
        if (!$booking) {
            return new \WP_Error('not_found', 'Rezervace nenalezena.', ['status' => 404]);
        }

        if (in_array($booking->paymentStatus, ['succeeded','captured'], true)) {
            return new \WP_Error('paid_booking', 'Nelze smazat zaplacenou rezervaci bez refundace.', ['status' => 409]);
        }

        $wpdb->delete($wpdb->prefix . 'duj_bookings', ['id' => $booking->id]);
        return new \WP_REST_Response(['deleted' => true]);
    }

    public function action(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $booking = $this->bookingRepo->findById((int) $req['id']);
        if (!$booking) {
            return new \WP_Error('not_found', 'Rezervace nenalezena.', ['status' => 404]);
        }

        $body      = $req->get_json_params();
        $action    = sanitize_key($body['action'] ?? '');
        $adminNote = isset($body['admin_note']) ? sanitize_text_field($body['admin_note']) : null;

        if ($adminNote !== null) {
            $this->bookingRepo->update($booking->id, ['admin_note' => $adminNote]);
        }

        $statusMap = [
            'confirm'   => 'confirmed',
            'reject'    => 'rejected',
            'cancel'    => 'cancelled',
            'mark_paid' => null,
        ];

        if (!array_key_exists($action, $statusMap)) {
            return new \WP_Error('invalid_action', 'Neplatná akce.', ['status' => 400]);
        }

        if ($action === 'mark_paid') {
            $this->bookingRepo->update($booking->id, ['payment_status' => 'succeeded']);
            return new \WP_REST_Response(['ok' => true]);
        }

        try {
            $newStatus = $statusMap[$action];
            $this->bookingService->transition($booking, $newStatus);
            $fresh = $this->bookingRepo->findById($booking->id);
            if ($fresh) {
                match ($action) {
                    'confirm' => $this->notificationService->sendConfirmed($fresh),
                    'cancel'  => $this->notificationService->sendCancelled($fresh),
                    'reject'  => $this->notificationService->sendCancelled($fresh),
                    default   => null,
                };
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_transition', $e->getMessage(), ['status' => 422]);
        }

        return new \WP_REST_Response(['ok' => true]);
    }

    public function bulk(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $body   = $req->get_json_params();
        $action = sanitize_key($body['action'] ?? '');
        $ids    = array_map('intval', (array)($body['ids'] ?? []));

        if (empty($ids)) {
            return new \WP_Error('no_ids', 'Žádné ID.', ['status' => 400]);
        }

        $statusMap = ['confirm' => 'confirmed', 'reject' => 'rejected', 'cancel' => 'cancelled'];
        if (!isset($statusMap[$action])) {
            return new \WP_Error('invalid_action', 'Neplatná akce.', ['status' => 400]);
        }

        $done = 0;
        foreach ($ids as $id) {
            $booking = $this->bookingRepo->findById($id);
            if (!$booking) continue;
            try {
                $this->bookingService->transition($booking, $statusMap[$action]);
                $done++;
            } catch (\InvalidArgumentException) { /* přeskočit neplatné přechody */ }
        }

        return new \WP_REST_Response(['done' => $done]);
    }

    public function calendar(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $from  = sanitize_text_field($req->get_param('from') ?? date('Y-m-01'));
        $to    = sanitize_text_field($req->get_param('to')   ?? date('Y-m-t'));
        $table = $wpdb->prefix . 'duj_bookings';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT booking_date, combo_key, status FROM `{$table}`
                 WHERE booking_date BETWEEN %s AND %s
                   AND status NOT IN ('cancelled','expired','rejected')
                 ORDER BY booking_date ASC",
                $from, $to
            ),
            ARRAY_A
        ) ?? [];

        $byDate = [];
        foreach ($rows as $r) {
            $d = $r['booking_date'];
            if (!isset($byDate[$d])) {
                $byDate[$d] = ['sud' => 'available', 'sauna' => 'available'];
            }
            foreach (['sud', 'sauna'] as $res) {
                if (str_contains($r['combo_key'], $res)) {
                    $byDate[$d][$res] = in_array($r['status'], ['confirmed','awaiting_confirmation'], true) ? 'booked' : 'partial';
                }
            }
        }

        return new \WP_REST_Response($byDate);
    }

    public function calendarDay(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $date = sanitize_text_field($req->get_param('date') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_Error('invalid_date', 'Neplatné datum.', ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'duj_bookings';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, reference, slot_from, slot_to, combo_key, customer_email, status FROM `{$table}` WHERE booking_date = %s ORDER BY slot_from ASC",
                $date
            ),
            ARRAY_A
        ) ?? [];

        return new \WP_REST_Response(['bookings' => $rows]);
    }

    private function buildWhere(string $status, string $service, string $date_from, string $date_to, string $search, \wpdb $wpdb): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($status !== '') { $where[] = 'status = %s'; $params[] = $status; }
        if ($service !== '') { $where[] = 'combo_key = %s'; $params[] = $service; }
        if ($date_from !== '') { $where[] = 'booking_date >= %s'; $params[] = $date_from; }
        if ($date_to !== '') { $where[] = 'booking_date <= %s'; $params[] = $date_to; }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(reference LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\BookingService;
use Duj\Wellness\Domain\ComboKey;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Repository\BookingRepository;
use Duj\Wellness\Support\Settings;

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

        register_rest_route('duj/v1', '/admin/bookings/manual', [
            ['methods' => 'POST', 'callback' => [$this, 'createManual'], 'permission_callback' => [$this, 'can']],
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
        $from = sanitize_text_field($req->get_param('from') ?? date('Y-m-01'));
        $to   = sanitize_text_field($req->get_param('to')   ?? date('Y-m-t'));

        // 1. Fetch schedule overrides in range.
        $overridesTable = $wpdb->prefix . 'duj_schedule_overrides';
        $overrideRows   = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT override_date, mode FROM `{$overridesTable}` WHERE override_date BETWEEN %s AND %s",
                $from, $to
            ),
            ARRAY_A
        ) ?? [];
        $overrides = [];
        foreach ($overrideRows as $ov) {
            $overrides[$ov['override_date']] = $ov['mode'];
        }

        // 2. Fetch active schedule rules with slot times, grouped by weekday.
        $rulesTable = $wpdb->prefix . 'duj_schedule_rules';
        $ruleRows   = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT weekday, time_from, resource_scope FROM `{$rulesTable}`
                 WHERE is_active = 1
                   AND (valid_from IS NULL OR valid_from <= %s)
                   AND (valid_to   IS NULL OR valid_to   >= %s)
                 ORDER BY time_from ASC",
                $to, $from
            ),
            ARRAY_A
        ) ?? [];

        $allResources = ['sud', 'sauna'];
        $rulesByWeekday = []; // weekday => time => ['sud','sauna']
        foreach ($ruleRows as $r) {
            $wd       = (int) $r['weekday'];
            $timeKey  = substr($r['time_from'], 0, 5); // HH:MM
            $scope    = isset($r['resource_scope']) ? json_decode($r['resource_scope'], true) : null;
            $resources = is_array($scope) ? $scope : $allResources;
            if (!isset($rulesByWeekday[$wd][$timeKey])) {
                $rulesByWeekday[$wd][$timeKey] = [];
            }
            foreach ($resources as $res) {
                $rulesByWeekday[$wd][$timeKey][$res] = true;
            }
        }

        // 3. Build base slot availability for every open day in range.
        $byDate  = [];
        $tz      = new \DateTimeZone('Europe/Prague');
        $current = new \DateTimeImmutable($from, $tz);
        $end     = new \DateTimeImmutable($to,   $tz);

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $isoDay  = (int) $current->format('N');

            $isClosed = isset($overrides[$dateStr]) && $overrides[$dateStr] === 'closed';
            $isOpen   = !$isClosed && isset($rulesByWeekday[$isoDay]);

            if ($isOpen) {
                $slots = [];
                foreach ($rulesByWeekday[$isoDay] as $timeKey => $resMap) {
                    foreach (array_keys($resMap) as $res) {
                        $slots[$timeKey][$res] = 'available';
                    }
                }
                if (!empty($slots)) {
                    $byDate[$dateStr] = ['slots' => $slots];
                }
            }

            $current = $current->modify('+1 day');
        }

        // 4. Overlay booking statuses by slot_from and resource.
        $bookingTable = $wpdb->prefix . 'duj_bookings';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT booking_date, slot_from, combo_key, status FROM `{$bookingTable}`
                 WHERE booking_date BETWEEN %s AND %s
                   AND status NOT IN ('cancelled','expired','rejected')
                 ORDER BY booking_date ASC, slot_from ASC",
                $from, $to
            ),
            ARRAY_A
        ) ?? [];

        foreach ($rows as $r) {
            $d       = $r['booking_date'];
            $slotKey = substr($r['slot_from'], 0, 5); // HH:MM
            $state   = in_array($r['status'], ['confirmed', 'awaiting_confirmation'], true) ? 'booked' : 'partial';

            if (!isset($byDate[$d])) {
                $byDate[$d] = ['slots' => []];
            }
            if (!isset($byDate[$d]['slots'][$slotKey])) {
                $byDate[$d]['slots'][$slotKey] = [];
            }
            foreach ($allResources as $res) {
                if (str_contains($r['combo_key'], $res)) {
                    $byDate[$d]['slots'][$slotKey][$res] = $state;
                }
            }
        }

        // Sort slots by time within each day.
        foreach ($byDate as &$dateData) {
            ksort($dateData['slots']);
        }
        unset($dateData);

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

    public function createManual(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body = $req->get_json_params();

        $date      = sanitize_text_field($body['booking_date'] ?? '');
        $slotFrom  = sanitize_text_field($body['slot_from']    ?? '');
        $slotTo    = sanitize_text_field($body['slot_to']      ?? '');
        $comboKey  = sanitize_key($body['combo_key']           ?? 'sud');
        $name      = sanitize_text_field($body['customer_name']  ?? '');
        $email     = sanitize_email($body['customer_email']      ?? '');
        $phone     = sanitize_text_field($body['customer_phone'] ?? '');
        $guests    = max(1, (int)($body['guests']               ?? 1));
        $note      = sanitize_text_field($body['customer_note']  ?? '');
        $adminNote = sanitize_text_field($body['admin_note']     ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_Error('invalid_date', 'Neplatné datum.', ['status' => 400]);
        }
        if (!preg_match('/^\d{2}:\d{2}/', $slotFrom) || !preg_match('/^\d{2}:\d{2}/', $slotTo)) {
            return new \WP_Error('invalid_time', 'Neplatný čas.', ['status' => 400]);
        }
        if (!in_array($comboKey, ['sud', 'sauna', 'sauna+sud'], true)) {
            return new \WP_Error('invalid_combo', 'Neplatná kombinace.', ['status' => 400]);
        }
        if ($email === '' || $phone === '') {
            return new \WP_Error('missing_fields', 'E-mail a telefon jsou povinné.', ['status' => 400]);
        }

        $uuid      = wp_generate_uuid4();
        $reference = 'M' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $now       = current_time('mysql', true);

        $table = $wpdb->prefix . 'duj_bookings';
        $wpdb->insert($table, [
            'uuid'           => $uuid,
            'reference'      => $reference,
            'booking_date'   => $date,
            'slot_from'      => $slotFrom,
            'slot_to'        => $slotTo,
            'combo_key'      => $comboKey,
            'guests'         => $guests,
            'status'         => 'confirmed',
            'tier_slug'      => 'public',
            'amount_minor'   => 0,
            'currency'       => 'CZK',
            'customer_name'  => $name !== '' ? $name : null,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'customer_note'  => $note !== '' ? $note : null,
            'admin_note'     => $adminNote !== '' ? $adminNote : null,
            'payment_method' => 'manual',
            'payment_status' => 'not_required',
            'source'         => 'admin',
            'locale'         => 'cs_CZ',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $bookingId = (int) $wpdb->insert_id;

        // Insert booking items so the slot is blocked in the availability overlap check.
        $this->insertManualBookingItems($wpdb, $bookingId, $uuid, $date, $slotFrom, $slotTo, $comboKey, $now);

        return new \WP_REST_Response(['id' => $bookingId, 'reference' => $reference], 201);
    }

    private function insertManualBookingItems(
        \wpdb $wpdb,
        int $bookingId,
        string $uuid,
        string $date,
        string $slotFrom,
        string $slotTo,
        string $comboKey,
        string $now,
    ): void {
        $resourceSlugs = ComboKey::toResourceSlugs($comboKey);
        if (empty($resourceSlugs)) {
            return;
        }

        $resourceTable = $wpdb->prefix . 'duj_resources';
        $placeholders  = implode(',', array_fill(0, count($resourceSlugs), '%s'));
        $resourceIds   = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("SELECT id FROM `{$resourceTable}` WHERE slug IN ($placeholders)", ...$resourceSlugs)
        ) ?? [];

        if (empty($resourceIds)) {
            return;
        }

        $tz            = new \DateTimeZone('Europe/Prague');
        $utcTz         = new \DateTimeZone('UTC');
        $bufferMinutes = Settings::instance()->bufferMinutes();
        $slotFromFull  = strlen($slotFrom) === 5 ? $slotFrom . ':00' : $slotFrom;
        $slotToFull    = strlen($slotTo)   === 5 ? $slotTo   . ':00' : $slotTo;

        $blockedFrom = (new \DateTimeImmutable("{$date} {$slotFromFull}", $tz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
        $blockedTo   = (new \DateTimeImmutable("{$date} {$slotToFull}", $tz))
            ->setTimezone($utcTz)
            ->modify("+{$bufferMinutes} minutes")
            ->format('Y-m-d H:i:s');

        $itemTable = $wpdb->prefix . 'duj_booking_items';
        foreach ($resourceIds as $resourceId) {
            $wpdb->insert($itemTable, [
                'booking_id'     => $bookingId,
                'resource_id'    => (int) $resourceId,
                'blocking_key'   => $uuid,
                'blocked_from'   => $blockedFrom,
                'blocked_to'     => $blockedTo,
                'buffer_minutes' => $bufferMinutes,
                'created_at'     => $now,
            ]);
        }
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

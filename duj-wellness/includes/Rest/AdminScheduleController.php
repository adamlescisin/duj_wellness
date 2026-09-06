<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\SlotGenerator;

/**
 * Admin REST endpointy pro správu rozvrhu.
 */
final class AdminScheduleController
{
    public function register(): void
    {
        $cb = fn($m, $h) => ['methods' => $m, 'callback' => [$this, $h], 'permission_callback' => [$this, 'can']];

        register_rest_route('duj/v1', '/admin/schedule/rules', [
            $cb('GET',  'listRules'),
            $cb('POST', 'createRule'),
        ]);
        register_rest_route('duj/v1', '/admin/schedule/rules/(?P<id>\d+)', [
            $cb('DELETE', 'deleteRule'),
        ]);
        register_rest_route('duj/v1', '/admin/schedule/overrides', [
            $cb('GET',  'listOverrides'),
            $cb('POST', 'createOverride'),
        ]);
        register_rest_route('duj/v1', '/admin/schedule/overrides/(?P<id>\d+)', [
            $cb('DELETE', 'deleteOverride'),
        ]);
        register_rest_route('duj/v1', '/admin/schedule/generate-slots', [
            $cb('POST', 'generateSlots'),
        ]);
        register_rest_route('duj/v1', '/admin/schedule/bulk', [
            $cb('POST', 'bulk'),
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function listRules(): \WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_schedule_rules';
        $rows  = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY weekday ASC, time_from ASC", ARRAY_A) ?? [];
        return new \WP_REST_Response($rows);
    }

    public function createRule(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body = $req->get_json_params();

        $weekday = (int) ($body['weekday'] ?? 0);
        if ($weekday < 1 || $weekday > 7) {
            return new \WP_Error('invalid_weekday', 'Weekday must be 1-7.', ['status' => 400]);
        }

        $from = sanitize_text_field($body['time_from'] ?? '');
        $to   = sanitize_text_field($body['time_to']   ?? '');
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $from) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $to)) {
            return new \WP_Error('invalid_time', 'Neplatný čas.', ['status' => 400]);
        }

        $wpdb->insert($wpdb->prefix . 'duj_schedule_rules', [
            'weekday'    => $weekday,
            'time_from'  => $from,
            'time_to'    => $to,
            'label'      => sanitize_text_field($body['label'] ?? ''),
            'valid_from' => !empty($body['valid_from']) ? sanitize_text_field($body['valid_from']) : null,
            'valid_to'   => !empty($body['valid_to'])   ? sanitize_text_field($body['valid_to'])   : null,
            'is_active'  => 1,
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    public function deleteRule(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $id = (int) $req['id'];
        $wpdb->delete($wpdb->prefix . 'duj_schedule_rules', ['id' => $id]);
        return new \WP_REST_Response(['deleted' => true]);
    }

    public function listOverrides(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_schedule_overrides';
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE override_date >= %s ORDER BY override_date ASC LIMIT 90", date('Y-m-d')),
            ARRAY_A
        ) ?? [];
        return new \WP_REST_Response($rows);
    }

    public function createOverride(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body = $req->get_json_params();
        $date = sanitize_text_field($body['override_date'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_Error('invalid_date', 'Neplatné datum.', ['status' => 400]);
        }

        $mode = sanitize_key($body['mode'] ?? 'closed');
        if (!in_array($mode, ['closed', 'custom', 'guests_only'], true)) {
            $mode = 'closed';
        }

        $slotsJson = null;
        if ($mode === 'custom' && !empty($body['slots']) && is_array($body['slots'])) {
            $slots = [];
            foreach ($body['slots'] as $s) {
                $from = sanitize_text_field($s['from'] ?? '');
                $to   = sanitize_text_field($s['to']   ?? '');
                if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $from) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $to)) {
                    $slots[] = ['from' => $from, 'to' => $to];
                }
            }
            $slotsJson = !empty($slots) ? wp_json_encode($slots) : null;
        }

        $table = $wpdb->prefix . 'duj_schedule_overrides';
        $note  = sanitize_text_field($body['note'] ?? '');

        // Upsert — přepíše existující výjimku pro stejné datum
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE override_date = %s", $date));
        if ($existing) {
            $wpdb->update($table, ['mode' => $mode, 'slots' => $slotsJson, 'note' => $note], ['id' => (int)$existing]);
            return new \WP_REST_Response(['id' => (int)$existing]);
        }

        $wpdb->insert($table, [
            'override_date' => $date,
            'mode'          => $mode,
            'slots'         => $slotsJson,
            'note'          => $note,
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    public function deleteOverride(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'duj_schedule_overrides', ['id' => (int) $req['id']]);
        return new \WP_REST_Response(['deleted' => true]);
    }

    public function generateSlots(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body = $req->get_json_params();

        $timeFrom      = sanitize_text_field($body['time_from']      ?? '16:00');
        $timeTo        = sanitize_text_field($body['time_to']        ?? '22:00');
        $slotMinutes   = max(30, min(480, (int) ($body['slot_minutes']   ?? 120)));
        $bufferMinutes = max(0,  min(240, (int) ($body['buffer_minutes'] ?? 60)));
        $weekdays      = array_map('intval', (array) ($body['weekdays'] ?? range(1, 7)));
        $isDryRun      = !empty($body['dry_run']);

        $validFrom = !empty($body['valid_from']) ? sanitize_text_field($body['valid_from']) : null;
        $validTo   = !empty($body['valid_to'])   ? sanitize_text_field($body['valid_to'])   : null;

        if ($validFrom !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
            return new \WP_Error('invalid_valid_from', 'Neplatné datum platnosti od.', ['status' => 400]);
        }
        if ($validTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo)) {
            return new \WP_Error('invalid_valid_to', 'Neplatné datum platnosti do.', ['status' => 400]);
        }
        if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
            return new \WP_Error('invalid_validity_range', 'Datum platnosti od musí být před datem platnosti do.', ['status' => 400]);
        }

        $weekdayLabels = [1=>'Po',2=>'Út',3=>'St',4=>'Čt',5=>'Pá',6=>'So',7=>'Ne'];

        $generator = new SlotGenerator();
        $slotObjects = $generator->generate($timeFrom, $timeTo, $slotMinutes, $bufferMinutes);
        $slots = array_map(fn($s) => ['from' => $s->from, 'to' => $s->to], $slotObjects);
        if (empty($slots)) {
            return new \WP_Error('no_slots', 'Okno není dostatečně velké pro ani jeden slot.', ['status' => 400]);
        }

        $preview = [];
        foreach ($weekdays as $wd) {
            foreach ($slots as $slot) {
                $preview[] = [
                    'weekday'       => $wd,
                    'weekday_label' => $weekdayLabels[$wd] ?? (string)$wd,
                    'time_from'     => $slot['from'],
                    'time_to'       => $slot['to'],
                    'valid_from'    => $validFrom,
                    'valid_to'      => $validTo,
                ];
            }
        }

        if ($isDryRun) {
            return new \WP_REST_Response(['slots' => $preview, 'valid_from' => $validFrom, 'valid_to' => $validTo]);
        }

        $table = $wpdb->prefix . 'duj_schedule_rules';
        $count = 0;
        foreach ($preview as $row) {
            $wpdb->insert($table, [
                'weekday'    => $row['weekday'],
                'time_from'  => $row['time_from'],
                'time_to'    => $row['time_to'],
                'label'      => "Generovaný slot {$row['time_from']}–{$row['time_to']}",
                'valid_from' => $validFrom,
                'valid_to'   => $validTo,
                'is_active'  => 1,
            ]);
            $count++;
        }

        return new \WP_REST_Response(['count' => $count]);
    }

    public function bulk(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body      = $req->get_json_params();
        $dateFrom  = sanitize_text_field($body['date_from'] ?? '');
        $dateTo    = sanitize_text_field($body['date_to']   ?? '');
        $action    = sanitize_key($body['action'] ?? 'close');
        $weekdays  = array_map('intval', (array)($body['weekdays'] ?? range(1, 7)));
        $isDryRun  = !empty($body['dry_run']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return new \WP_Error('invalid_date', 'Neplatné datum.', ['status' => 400]);
        }

        // Generuj seznam dat v rozsahu
        $dates = [];
        $cur   = new \DateTimeImmutable($dateFrom, new \DateTimeZone('Europe/Prague'));
        $end   = new \DateTimeImmutable($dateTo,   new \DateTimeZone('Europe/Prague'));
        while ($cur <= $end) {
            $wd = (int) $cur->format('N');
            if (in_array($wd, $weekdays, true)) {
                $dates[] = $cur->format('Y-m-d');
            }
            $cur = $cur->modify('+1 day');
        }

        if (empty($dates)) {
            return new \WP_REST_Response(['affected_days' => 0, 'conflicting_bookings' => 0]);
        }

        // Zkontroluj kolize s potvrzenými rezervacemi
        $placeholders = implode(',', array_fill(0, count($dates), '%s'));
        $bookingsTable = $wpdb->prefix . 'duj_bookings';
        $conflicts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$bookingsTable}` WHERE booking_date IN ({$placeholders}) AND status IN ('confirmed','awaiting_confirmation')",
                ...$dates
            )
        );

        if ($isDryRun) {
            return new \WP_REST_Response([
                'affected_days'        => count($dates),
                'conflicting_bookings' => $conflicts,
            ]);
        }

        $table = $wpdb->prefix . 'duj_schedule_overrides';

        foreach ($dates as $date) {
            $mode = match ($action) {
                'close'          => 'closed',
                'open'           => 'open',
                'set_slots'      => 'custom',
                'delete_overrides' => null,
                default          => 'closed',
            };

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE override_date = %s", $date));

            if ($action === 'delete_overrides') {
                if ($existing) $wpdb->delete($table, ['id' => (int)$existing]);
                continue;
            }

            if ($existing) {
                $wpdb->update($table, ['mode' => $mode], ['id' => (int)$existing]);
            } else {
                $wpdb->insert($table, ['override_date' => $date, 'mode' => $mode]);
            }
        }

        return new \WP_REST_Response(['affected_days' => count($dates), 'conflicting_bookings' => $conflicts]);
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Accommodation\AccommodationClassifier;
use Duj\Wellness\Accommodation\AccommodationSyncService;
use Duj\Wellness\Accommodation\IcsParser;
use Duj\Wellness\Repository\AccommodationRepository;

/**
 * Admin REST endpointy pro správu ubytování.
 */
final class AdminAccommodationController
{
    public function register(): void
    {
        $cb = fn($m, $h) => ['methods' => $m, 'callback' => [$this, $h], 'permission_callback' => [$this, 'can']];

        register_rest_route('duj/v1', '/admin/accommodation', [
            $cb('GET',  'list'),
            $cb('POST', 'setPolicy'),
        ]);
        register_rest_route('duj/v1', '/admin/accommodation/sync', [
            $cb('POST', 'sync'),
        ]);
        register_rest_route('duj/v1', '/admin/accommodation/import-csv', [
            $cb('POST', 'importCsv'),
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function list(\WP_REST_Request $req): \WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_accommodation_blocks';
        $from  = date('Y-m-d');
        $to    = date('Y-m-d', strtotime('+90 days'));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE block_date BETWEEN %s AND %s ORDER BY block_date ASC", $from, $to),
            ARRAY_A
        ) ?? [];
        return new \WP_REST_Response($rows);
    }

    public function setPolicy(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        global $wpdb;
        $body   = $req->get_json_params();
        $date   = sanitize_text_field($body['date'] ?? '');
        $policy = sanitize_key($body['policy'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_Error('invalid_date', 'Neplatné datum.', ['status' => 400]);
        }

        if (!in_array($policy, ['ignore', 'guests_only', 'closed'], true)) {
            return new \WP_Error('invalid_policy', 'Neplatná politika.', ['status' => 400]);
        }

        $table    = $wpdb->prefix . 'duj_accommodation_blocks';
        $isManual = !empty($body['is_manual']) ? 1 : 0;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE block_date = %s", $date));

        if ($existing) {
            $wpdb->update($table, ['policy' => $policy, 'is_manual' => $isManual], ['id' => (int)$existing]);
        } else {
            $wpdb->insert($table, ['block_date' => $date, 'policy' => $policy, 'is_manual' => $isManual, 'source' => 'manual']);
        }

        return new \WP_REST_Response(['updated' => true]);
    }

    public function sync(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        try {
            $syncSvc = new AccommodationSyncService(
                new AccommodationRepository(),
                new IcsParser(),
                new AccommodationClassifier(),
            );

            $imported = $syncSvc->sync();

            update_option('duj_accommodation_sync_meta', [
                'last_sync'  => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'),
                'last_error' => null,
            ], false);

            return new \WP_REST_Response(['imported' => $imported]);

        } catch (\Throwable $e) {
            update_option('duj_accommodation_sync_meta', [
                'last_sync'  => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s'),
                'last_error' => $e->getMessage(),
            ], false);

            return new \WP_Error('sync_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function importCsv(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $files  = $req->get_file_params();
        $isDryRun = (int)($req->get_param('dry_run') ?? 0) === 1;

        if (empty($files['csv_file']['tmp_name'])) {
            return new \WP_Error('no_file', 'Nebyl nahrán žádný soubor.', ['status' => 400]);
        }

        $tmpFile = $files['csv_file']['tmp_name'];
        if (!is_file($tmpFile)) {
            return new \WP_Error('invalid_file', 'Neplatný soubor.', ['status' => 400]);
        }

        $handle  = fopen($tmpFile, 'r');
        if (!$handle) {
            return new \WP_Error('file_read_error', 'Nepodařilo se otevřít soubor.', ['status' => 500]);
        }

        $header  = null;
        $rows    = [];
        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            if ($header === null) { $header = $line; continue; }
            if (count($line) >= 2) {
                $rows[] = array_combine($header, array_slice($line, 0, count($header)));
            }
        }
        fclose($handle);

        // Detekuj sloupce pro datum příjezdu a odjezdu (heuristika)
        $dateFromKey = null;
        $dateToKey   = null;
        foreach ($header ?? [] as $col) {
            $lower = strtolower($col);
            if (str_contains($lower, 'arrival') || str_contains($lower, 'check') || str_contains($lower, 'from') || str_contains($lower, 'start')) {
                $dateFromKey = $col;
            }
            if (str_contains($lower, 'departure') || str_contains($lower, 'checkout') || str_contains($lower, 'to') || str_contains($lower, 'end')) {
                $dateToKey = $col;
            }
        }

        if (!$dateFromKey || !$dateToKey) {
            return new \WP_Error('csv_mapping', 'Nepodařilo se automaticky namapovat sloupce data. Exportujte CSV s hlavičkou arrival/departure nebo from/to.', ['status' => 422]);
        }

        global $wpdb;
        $blockTable  = $wpdb->prefix . 'duj_accommodation_blocks';
        $bookingTable = $wpdb->prefix . 'duj_bookings';

        $toImport  = 0;
        $conflicts = 0;
        $datesSet  = [];

        foreach ($rows as $row) {
            $fromDate = sanitize_text_field($row[$dateFromKey] ?? '');
            $toDate   = sanitize_text_field($row[$dateToKey]   ?? '');

            // Normalizuj na Y-m-d
            try {
                $f = new \DateTimeImmutable($fromDate);
                $t = new \DateTimeImmutable($toDate);
            } catch (\Exception) { continue; }

            $cur = $f;
            while ($cur < $t) {
                $ds = $cur->format('Y-m-d');
                if (!isset($datesSet[$ds])) {
                    $datesSet[$ds] = true;
                    $toImport++;

                    if (!$isDryRun) {
                        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, is_manual FROM `{$blockTable}` WHERE block_date = %s", $ds), ARRAY_A);
                        if ($existing && (int)$existing['is_manual']) {
                            // ManualSource vždy vyhrává
                        } elseif ($existing) {
                            $wpdb->update($blockTable, ['policy' => 'guests_only', 'source' => 'csv'], ['id' => (int)$existing['id']]);
                        } else {
                            $wpdb->insert($blockTable, ['block_date' => $ds, 'policy' => 'guests_only', 'source' => 'csv', 'is_manual' => 0]);
                        }
                    }

                    // Zkontroluj kolize s existujícími potvr. rezervacemi
                    $conflict = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$bookingTable}` WHERE booking_date = %s AND status IN ('confirmed','awaiting_confirmation')",
                        $ds
                    ));
                    if ((int)$conflict > 0) $conflicts++;
                }
                $cur = $cur->modify('+1 day');
            }
        }

        return new \WP_REST_Response([
            'to_import' => $toImport,
            'imported'  => $isDryRun ? 0 : $toImport,
            'conflicts' => $conflicts,
        ]);
    }
}

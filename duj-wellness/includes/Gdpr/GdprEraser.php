<?php

declare(strict_types=1);

namespace Duj\Wellness\Gdpr;

/**
 * Mazač osobních dat pro WP Privacy Tools.
 *
 * Registruje se přes filtr wp_privacy_personal_data_erasers.
 * Anonymizuje osobní data zákazníka — rezervaci samotnou zachovává
 * pro účetní a provozní účely.
 *
 * Anonymizovaná pole:
 *   customer_name  → NULL
 *   customer_email → '[anonymizováno]'
 *   customer_phone → '[anonymizováno]'
 *   customer_note  → NULL
 *   consent_ip     → NULL (VARBINARY)
 *
 * Rezervace se statusem pending/awaiting_confirmation/hold jsou smazány celé,
 * protože jde o nedokončené transakce bez účetní hodnoty.
 */
final class GdprEraser
{
    public function register(): void
    {
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    public function registerEraser(array $erasers): array
    {
        $erasers['duj-wellness'] = [
            'eraser_friendly_name' => __('Wellness rezervace', 'duj-wellness'),
            'callback'             => [$this, 'erase'],
        ];
        return $erasers;
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        global $wpdb;

        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $table   = $wpdb->prefix . 'duj_bookings';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status FROM `{$table}` WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $email, $perPage, $offset
            ),
            ARRAY_A
        ) ?? [];

        $removed  = false;
        $retained = false;
        $messages = [];

        $deletableStatuses = ['pending', 'awaiting_confirmation', 'hold', 'expired'];

        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $status = $row['status'];

            if (in_array($status, $deletableStatuses, true)) {
                $wpdb->delete($table, ['id' => $id]);
                $removed = true;
            } else {
                // Anonymizuj — zachovej záznam pro provozní a účetní účely
                $wpdb->update(
                    $table,
                    [
                        'customer_name'  => null,
                        'customer_email' => '[anonymizováno]',
                        'customer_phone' => '[anonymizováno]',
                        'customer_note'  => null,
                        'consent_ip'     => null,
                    ],
                    ['id' => $id]
                );
                $removed  = true;
                $retained = true;
            }
        }

        if ($retained) {
            $messages[] = __('Rezervace s dokončenými platbami jsou zachovány v anonymizované podobě pro účetní účely.', 'duj-wellness');
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => count($rows) < $perPage,
        ];
    }
}

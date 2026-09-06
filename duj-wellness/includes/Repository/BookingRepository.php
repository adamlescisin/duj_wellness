<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

final class BookingRepository implements BookingRepositoryInterface
{
    public function __construct(private readonly \wpdb $wpdb) {}

    public function findById(int $id): ?BookingRow
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUuid(string $uuid): ?BookingRow
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE uuid = %s", $uuid),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findByReference(string $reference): ?BookingRow
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE reference = %s", $reference),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function findByPaymentIntentId(string $intentId): ?BookingRow
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$table}` WHERE payment_intent_id = %s LIMIT 1", $intentId),
            ARRAY_A
        );
        return $row ? $this->hydrate($row) : null;
    }

    /** @return BookingRow[] */
    public function findExpiredHolds(\DateTimeImmutable $before): array
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $rows  = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE status = 'pending_payment' AND hold_expires_at <= %s",
                $before->format('Y-m-d H:i:s')
            ),
            ARRAY_A
        );
        return array_map([$this, 'hydrate'], $rows ?? []);
    }

    public function insert(array $data): int
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $this->wpdb->insert($table, $data);
        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, array $data): void
    {
        $table = $this->wpdb->prefix . 'duj_bookings';
        $this->wpdb->update($table, $data, ['id' => $id]);
    }

    public function incrementAccessCodeUsage(string $code): void
    {
        $table = $this->wpdb->prefix . 'duj_access_codes';
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE `{$table}` SET used_count = used_count + 1 WHERE code = %s",
                $code
            )
        );
    }

    private function hydrate(array $row): BookingRow
    {
        return new BookingRow(
            id:              (int) $row['id'],
            uuid:            $row['uuid'],
            reference:       $row['reference'],
            bookingDate:     $row['booking_date'],
            slotFrom:        $row['slot_from'],
            slotTo:          $row['slot_to'],
            comboKey:        $row['combo_key'],
            guests:          isset($row['guests']) ? (int) $row['guests'] : null,
            status:          $row['status'],
            tierSlug:        $row['tier_slug'],
            accessCode:      $row['access_code'] ?? null,
            amountMinor:     (int) $row['amount_minor'],
            currency:        $row['currency'],
            customerName:    $row['customer_name'] ?? null,
            customerEmail:   $row['customer_email'],
            customerPhone:   $row['customer_phone'],
            customerNote:    $row['customer_note'] ?? null,
            adminNote:       $row['admin_note'] ?? null,
            paymentMethod:   $row['payment_method'],
            paymentStatus:   $row['payment_status'],
            paymentProvider: $row['payment_provider'] ?? null,
            paymentIntentId: $row['payment_intent_id'] ?? null,
            paymentMeta:     isset($row['payment_meta']) ? json_decode($row['payment_meta'], true) : null,
            holdExpiresAt:   $row['hold_expires_at'] ?? null,
            authExpiresAt:   $row['auth_expires_at'] ?? null,
            confirmedAt:     $row['confirmed_at'] ?? null,
            confirmedBy:     isset($row['confirmed_by']) ? (int) $row['confirmed_by'] : null,
            cancelledAt:     $row['cancelled_at'] ?? null,
            cancelReason:    $row['cancel_reason'] ?? null,
            consentAt:       $row['consent_at'] ?? null,
            source:          $row['source'],
            locale:          $row['locale'],
            createdAt:       $row['created_at'],
            updatedAt:       $row['updated_at'],
        );
    }
}

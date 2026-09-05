<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification;

/**
 * Spravuje jednorázové action tokeny z e-mailů (confirm / reject / cancel / view).
 *
 * BEZPEČNOSTNÍ PRAVIDLA:
 * - Plaintext token se NIKDY neukládá — pouze hash('sha256', token).
 * - GET endpoint NIKDY neprovádí akci — pouze zobrazí stránku s formulářem.
 * - Po použití jednoho tokenu (confirm/reject) jsou zneplatněny OBA tokeny dané rezervace.
 * - TTL 14 dní, jednorázový (used_at).
 * - Rate-limit 10 pokusů / IP / hodinu (implementováno v ActionController).
 */
final class ActionTokenService implements ActionTokenServiceInterface
{
    private const TTL_DAYS = 14;

    public function __construct(private readonly \wpdb $wpdb) {}

    /**
     * Vygeneruje plaintext token a uloží jeho hash do DB.
     * Vrátí plaintext token pro vložení do e-mailové URL.
     */
    public function create(int $bookingId, string $action): string
    {
        $token    = bin2hex(random_bytes(32)); // 64 hex znaků
        $hash     = hash('sha256', $token);
        $table    = $this->wpdb->prefix . 'duj_action_tokens';
        $nowUtc   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $nowUtc->modify('+' . self::TTL_DAYS . ' days')->format('Y-m-d H:i:s');
        $createdAt = $nowUtc->format('Y-m-d H:i:s');

        $this->wpdb->insert($table, [
            'booking_id' => $bookingId,
            'action'     => $action,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
            'used_at'    => null,
            'used_ip'    => null,
            'created_at' => $createdAt,
        ]);

        return $token;
    }

    /**
     * Načte a ověří token.
     * Vrátí null, pokud neexistuje, expiroval nebo byl již použit.
     *
     * @return array{booking_id: int, action: string}|null
     */
    public function consume(string $token, string $clientIp): ?array
    {
        $hash  = hash('sha256', $token);
        $table = $this->wpdb->prefix . 'duj_action_tokens';
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, booking_id, action, expires_at, used_at
                 FROM `{$table}`
                 WHERE token_hash = %s
                 LIMIT 1",
                $hash
            ),
            ARRAY_A
        );

        if ($row === null) {
            return null;
        }

        if ($row['used_at'] !== null) {
            return null; // Již použitý
        }

        if ($row['expires_at'] < $nowUtc) {
            return null; // Expiroval
        }

        // Označ jako použitý
        $ipBin = inet_pton($clientIp);
        $this->wpdb->update(
            $table,
            ['used_at' => $nowUtc, 'used_ip' => $ipBin !== false ? $ipBin : null],
            ['id' => (int) $row['id']]
        );

        // Zneplatni všechny ostatní tokeny dané rezervace (confirm/reject)
        $this->invalidateOthers((int) $row['booking_id'], (int) $row['id']);

        return [
            'booking_id' => (int) $row['booking_id'],
            'action'     => $row['action'],
        ];
    }

    /**
     * Načte metadata tokenu BEZ spotřebování (pro GET — zobrazení stránky).
     * Vrátí null, pokud token neexistuje, expiroval nebo byl již použit.
     *
     * @return array{booking_id: int, action: string}|null
     */
    public function peek(string $token): ?array
    {
        $hash   = hash('sha256', $token);
        $table  = $this->wpdb->prefix . 'duj_action_tokens';
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT booking_id, action, expires_at, used_at
                 FROM `{$table}`
                 WHERE token_hash = %s
                 LIMIT 1",
                $hash
            ),
            ARRAY_A
        );

        if ($row === null || $row['used_at'] !== null || $row['expires_at'] < $nowUtc) {
            return null;
        }

        return [
            'booking_id' => (int) $row['booking_id'],
            'action'     => $row['action'],
        ];
    }

    /** Zneplatní všechny ostatní tokeny dané rezervace (setne used_at = now). */
    private function invalidateOthers(int $bookingId, int $exceptId): void
    {
        $table  = $this->wpdb->prefix . 'duj_action_tokens';
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE `{$table}` SET used_at = %s WHERE booking_id = %d AND id <> %d AND used_at IS NULL",
                $nowUtc,
                $bookingId,
                $exceptId
            )
        );
    }
}

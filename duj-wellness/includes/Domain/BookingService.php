<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

use Duj\Wellness\Repository\BookingItemRepositoryInterface;
use Duj\Wellness\Repository\BookingRepository;
use Duj\Wellness\Repository\BookingRepositoryInterface;
use Duj\Wellness\Repository\DayLockRepositoryInterface;
use Duj\Wellness\Repository\PriceRepositoryInterface;
use Duj\Wellness\Repository\ResourceRepositoryInterface;
use Duj\Wellness\Support\SettingsInterface;

/**
 * Hlavní service pro správu rezervací.
 *
 * KRITICKÉ INVARIANTY:
 * - Status se mění VÝHRADNĚ přes transition() — nikde jinde.
 * - blocking_key se nastaví jednou při vytvoření (pending_payment) a zruší při zrušení/expiraci.
 * - used_count přístupového kódu se inkrementuje AŽ při přechodu do awaiting_confirmation.
 * - Interval overlap check probíhá pod SELECT FOR UPDATE na duj_day_locks.
 * - Zámky se berou v pořadí resource_id ASC (prevence deadlocku).
 */
final class BookingService implements BookingServiceInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookingRepo,
        private readonly BookingItemRepositoryInterface $itemRepo,
        private readonly DayLockRepositoryInterface $dayLockRepo,
        private readonly PriceRepositoryInterface $priceRepo,
        private readonly ResourceRepositoryInterface $resourceRepo,
        private readonly AvailabilityServiceInterface $availabilityService,
        private readonly PricingService $pricingService,
        private readonly SettingsInterface $settings,
        private readonly \wpdb $wpdb,
    ) {}

    /**
     * Vytvoří novou rezervaci v stavu pending_payment.
     *
     * Postup:
     * 1. Spustí transakci + zamkne lock řádky (SELECT FOR UPDATE)
     * 2. Zkontroluje překryv intervalů
     * 3. Zapíše booking + booking_items (blocking_key = UUID)
     * 4. Commitne transakci
     */
    public function create(BookingRequest $req): BookingResult
    {
        $tz      = new \DateTimeZone('Europe/Prague');
        $utcTz   = new \DateTimeZone('UTC');
        $nowUtc  = new \DateTimeImmutable('now', $utcTz);
        $nowUtcS = $nowUtc->format('Y-m-d H:i:s');

        $normalizedCombo = ComboKey::normalize($req->comboKey);
        if (!ComboKey::isValid($normalizedCombo)) {
            return BookingResult::fail('invalid_combo', __('Neplatná kombinace zdrojů.', 'duj-wellness'));
        }

        // Cena
        $tier = $this->priceRepo->findTierBySlug($req->tierSlug);
        if ($tier === null) {
            return BookingResult::fail('invalid_tier', __('Neplatná cenová hladina.', 'duj-wellness'));
        }

        try {
            $money = $this->pricingService->resolvePrice($tier, $normalizedCombo, $req->bookingDate, $req->slotFrom);
        } catch (\RuntimeException) {
            return BookingResult::fail('price_not_found', __('Cena pro vybranou kombinaci není dostupná.', 'duj-wellness'));
        }

        // Zdroje pro combo
        $resourceSlugs = ComboKey::toResourceSlugs($normalizedCombo);
        $resourceIds   = $this->resourceRepo->findIdsBySlugs($resourceSlugs);

        if (count($resourceIds) !== count($resourceSlugs)) {
            return BookingResult::fail('resource_not_found', __('Zdroj rezervace není dostupný.', 'duj-wellness'));
        }

        sort($resourceIds); // ASC pro prevenci deadlocku

        // Slot okno v UTC
        $bufferMinutes = $this->settings->bufferMinutes();
        $slotFromFull  = strlen($req->slotFrom) === 5 ? $req->slotFrom . ':00' : $req->slotFrom;

        // Slot_to z dostupnosti (neznáme ho přimo z requestu — dohledáme ze slotů)
        $slotTo = $this->resolveSlotTo($req->bookingDate, $req->slotFrom);
        if ($slotTo === null) {
            return BookingResult::fail('slot_not_found', __('Vybraný slot nebyl nalezen v rozvrhu.', 'duj-wellness'));
        }
        $slotToFull = strlen($slotTo) === 5 ? $slotTo . ':00' : $slotTo;

        $winFrom = (new \DateTimeImmutable("{$req->bookingDate} $slotFromFull", $tz))->setTimezone($utcTz);
        $winTo   = (new \DateTimeImmutable("{$req->bookingDate} $slotToFull",   $tz))
                     ->setTimezone($utcTz)
                     ->modify("+{$bufferMinutes} minutes");

        $holdMinutes   = $this->settings->holdMinutes();
        $holdExpiresAt = $nowUtc->modify("+{$holdMinutes} minutes")->format('Y-m-d H:i:s');

        $uuid      = wp_generate_uuid4();
        $reference = $this->generateReference($req->bookingDate);

        $this->wpdb->query('START TRANSACTION');

        try {
            $this->dayLockRepo->lockRows($req->bookingDate, $resourceIds);

            // Kontrola překryvu — pod zámkem
            if ($this->hasOverlap($resourceIds, $winFrom, $winTo, $nowUtc)) {
                $this->wpdb->query('ROLLBACK');
                return BookingResult::fail('slot_taken', __('Vybraný termín byl právě obsazen. Zvolte prosím jiný.', 'duj-wellness'));
            }

            // Vložit booking
            $bookingId = $this->bookingRepo->insert([
                'uuid'             => $uuid,
                'reference'        => $reference,
                'booking_date'     => $req->bookingDate,
                'slot_from'        => $req->slotFrom,
                'slot_to'          => $slotTo,
                'combo_key'        => $normalizedCombo,
                'guests'           => $req->guests,
                'status'           => BookingStatus::PENDING_PAYMENT->value,
                'tier_slug'        => $req->tierSlug,
                'access_code'      => $req->validCode,
                'amount_minor'     => $money->amountMinor,
                'currency'         => $money->currency,
                'customer_name'    => $req->customerName,
                'customer_email'   => $req->customerEmail,
                'customer_phone'   => $req->customerPhone,
                'customer_note'    => $req->customerNote,
                'payment_method'   => $req->paymentMethod,
                'payment_status'   => 'pending',
                'hold_expires_at'  => $holdExpiresAt,
                'consent_at'       => $nowUtcS,
                'consent_ip'       => $req->consentIpBin,
                'source'           => $req->source,
                'locale'           => $req->locale,
                'created_at'       => $nowUtcS,
                'updated_at'       => $nowUtcS,
            ]);

            // Vložit booking items (blocking_key = UUID rezervace)
            $items = [];
            foreach ($resourceIds as $resourceId) {
                $items[] = [
                    'resource_id'    => $resourceId,
                    'blocking_key'   => $uuid,
                    'blocked_from'   => $winFrom->format('Y-m-d H:i:s'),
                    'blocked_to'     => $winTo->format('Y-m-d H:i:s'),
                    'buffer_minutes' => $bufferMinutes,
                ];
            }
            $this->itemRepo->insertItems($bookingId, $items, $nowUtcS);

            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            error_log('[duj-wellness] BookingService::create failed: ' . $e->getMessage());
            return BookingResult::fail('db_error', __('Chyba při ukládání rezervace. Zkuste to prosím znovu.', 'duj-wellness'));
        }

        return BookingResult::ok($bookingId, $uuid, $reference);
    }

    /**
     * Přechod stavu rezervace.
     * JEDINÉ místo, kde se mění status.
     *
     * @throws \InvalidArgumentException Pokud přechod není povolený.
     * @throws \RuntimeException         Pokud rezervace neexistuje.
     */
    public function transition(int $bookingId, BookingStatus $to, array $extra = []): void
    {
        $booking = $this->bookingRepo->findById($bookingId);
        if ($booking === null) {
            throw new \RuntimeException("Rezervace #{$bookingId} neexistuje.");
        }

        $from = BookingStatus::from($booking->status);

        if (!$from->canTransitionTo($to)) {
            throw new \InvalidArgumentException(sprintf(
                'Přechod stavu %s → %s není povolen.',
                $from->value,
                $to->value
            ));
        }

        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $data = array_merge($extra, [
            'status'     => $to->value,
            'updated_at' => $nowUtc,
        ]);

        // Speciální akce při přechodu
        match ($to) {
            BookingStatus::AWAITING_CONFIRMATION => $this->onAwaitingConfirmation($booking, $data),
            BookingStatus::CONFIRMED             => $this->onConfirmed($data, $nowUtc),
            BookingStatus::CANCELLED,
            BookingStatus::REJECTED,
            BookingStatus::EXPIRED              => $this->onTerminated($bookingId, $data, $nowUtc),
            default                              => null,
        };

        $this->bookingRepo->update($bookingId, $data);
    }

    /** Přechodová akce: awaiting_confirmation — inkrementuj used_count kódu. */
    private function onAwaitingConfirmation(\Duj\Wellness\Repository\BookingRow $booking, array &$data): void
    {
        if ($booking->accessCode !== null) {
            $this->bookingRepo->incrementAccessCodeUsage($booking->accessCode);
        }
    }

    /** Přechodová akce: confirmed — zaznamenej čas potvrzení. */
    private function onConfirmed(array &$data, string $nowUtc): void
    {
        $data['confirmed_at'] = $nowUtc;
    }

    /** Přechodová akce: cancelled/rejected/expired — uvolni slot. */
    private function onTerminated(int $bookingId, array &$data, string $nowUtc): void
    {
        $this->itemRepo->releaseByBookingId($bookingId);
        if (!isset($data['cancelled_at'])) {
            $data['cancelled_at'] = $nowUtc;
        }
    }

    /**
     * Hromadně expiruje hold rezervace.
     * Voláno z cronu každou minutu.
     */
    public function expireHolds(): int
    {
        $nowUtc  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expired = $this->bookingRepo->findExpiredHolds($nowUtc);
        $count   = 0;

        foreach ($expired as $booking) {
            try {
                $this->transition($booking->id, BookingStatus::EXPIRED);
                $count++;
            } catch (\Throwable $e) {
                error_log('[duj-wellness] expireHolds failed for booking #' . $booking->id . ': ' . $e->getMessage());
            }
        }

        return $count;
    }

    /** Zkontroluje, zda má některý ze zdrojů překrývající se rezervaci. */
    private function hasOverlap(array $resourceIds, \DateTimeImmutable $winFrom, \DateTimeImmutable $winTo, \DateTimeImmutable $nowUtc): bool
    {
        if (empty($resourceIds)) {
            return false;
        }

        $table   = $this->wpdb->prefix . 'duj_booking_items';
        $tableBk = $this->wpdb->prefix . 'duj_bookings';
        $nowUtcS = $nowUtc->format('Y-m-d H:i:s');

        $placeholders = implode(',', array_fill(0, count($resourceIds), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $prepareArgs = array_merge(
            $resourceIds,
            [$winTo->format('Y-m-d H:i:s'), $winFrom->format('Y-m-d H:i:s'), $nowUtcS]
        );

        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*)
                 FROM `{$table}` bi
                 JOIN `{$tableBk}` b ON b.id = bi.booking_id
                 WHERE bi.resource_id IN ($placeholders)
                   AND bi.blocked_from < %s
                   AND bi.blocked_to   > %s
                   AND bi.blocking_key IS NOT NULL
                   AND (b.status <> 'pending_payment' OR b.hold_expires_at > %s)",
                ...$prepareArgs
            )
        );

        return $count > 0;
    }

    /** Dohledá slot_to ze schedule (potřebujeme jej do booking_items). */
    private function resolveSlotTo(string $date, string $slotFrom): ?string
    {
        // Dostaneme sloty z availability service — hledáme slot s odpovídajícím from
        // Tato metoda je volána před overlap checkem, mimo transakci.
        // Používáme přímý dotaz do schedule (nepoužíváme availability cache).
        global $wpdb;

        $tz      = new \DateTimeZone('Europe/Prague');
        $weekday = (int) (new \DateTimeImmutable($date, $tz))->format('N');
        $table   = $wpdb->prefix . 'duj_schedule_rules';

        // Výjimka
        $overrideTable = $wpdb->prefix . 'duj_schedule_overrides';
        $override = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$overrideTable}` WHERE override_date = %s", $date),
            ARRAY_A
        );

        if ($override !== null && $override['mode'] === 'closed') {
            return null;
        }

        if ($override !== null && $override['mode'] === 'replace' && !empty($override['slots'])) {
            $slots = json_decode($override['slots'], true) ?? [];
            foreach ($slots as $slot) {
                if (isset($slot['from']) && $this->timesMatch($slot['from'], $slotFrom)) {
                    return $slot['to'];
                }
            }
            return null;
        }

        // Pravidla rozvrhu
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT time_from, time_to FROM `{$table}`
                 WHERE weekday = %d AND is_active = 1
                   AND (valid_from IS NULL OR valid_from <= %s)
                   AND (valid_to IS NULL OR valid_to >= %s)
                 ORDER BY time_from",
                $weekday,
                $date,
                $date
            ),
            ARRAY_A
        );

        foreach ($rows ?? [] as $row) {
            if ($this->timesMatch($row['time_from'], $slotFrom)) {
                return substr($row['time_to'], 0, 5); // HH:MM
            }
        }

        return null;
    }

    private function timesMatch(string $a, string $b): bool
    {
        return substr($a, 0, 5) === substr($b, 0, 5);
    }

    private function generateReference(string $date): string
    {
        $ymd  = str_replace('-', '', $date);
        $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return "W{$ymd}{$rand}";
    }
}

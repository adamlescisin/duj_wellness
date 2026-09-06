<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

use Duj\Wellness\Repository\AccommodationRepositoryInterface;
use Duj\Wellness\Support\Settings;

/**
 * Hlavní orchestrátor dostupnosti.
 * Implementuje pseudokód getAvailability() ze spec § 5.2.
 */
final class AvailabilityService implements AvailabilityServiceInterface
{
    public function __construct(
        private readonly ScheduleResolver $scheduleResolver,
        private readonly PricingService $pricingService,
        private readonly CutoffPolicy $cutoffPolicy,
        private readonly Settings $settings,
    ) {}

    /**
     * Vrátí dostupnost pro rozsah dat.
     *
     * @return AvailabilityDay[]
     */
    public function getAvailability(string $from, string $to, PriceTier $tier): array
    {
        global $wpdb;

        $tz = new \DateTimeZone('Europe/Prague');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        $cursor = new \DateTimeImmutable($from, $tz);
        $end    = new \DateTimeImmutable($to, $tz);

        $days = [];

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');

            // Minulost
            if ($date < $today) {
                $days[] = new AvailabilityDay($date, 'past', [], []);
                $cursor = $cursor->modify('+1 day');
                continue;
            }

            $policy = $this->scheduleResolver->resolveDayPolicy($date);
            $slots  = $this->scheduleResolver->resolveSlots($date);

            // Dny vyhrazené ubytovaným — veřejnost vidí hlášku, ne "obsazeno"
            if ($policy === 'guests_only' && $tier->slug === 'public') {
                $days[] = new AvailabilityDay($date, 'guests_only', [], []);
                $cursor = $cursor->modify('+1 day');
                continue;
            }

            if (empty($slots)) {
                $days[] = new AvailabilityDay($date, 'closed', [], []);
                $cursor = $cursor->modify('+1 day');
                continue;
            }

            $availableSlots = [];
            $bufferMinutes = $this->settings->bufferMinutes();

            foreach ($slots as $slot) {
                // Cutoff kontrola
                if (!$this->cutoffPolicy->allows($date, $slot->from, $tier)) {
                    $availableSlots[] = new AvailabilitySlot(
                        from: $slot->from,
                        to: $slot->to,
                        status: 'cutoff',
                        offers: [],
                    );
                    continue;
                }

                // UTC okno = slot_from až slot_to + buffer (pro překryvovou kontrolu)
                $winFrom = $this->toUtc($date, $slot->from, $tz);
                $winTo   = $this->toUtc($date, $slot->to, $tz)->modify("+{$bufferMinutes} minutes");

                // Zjistit obsazené zdroje (překryv intervalů)
                $busyResourceIds = $this->getBusyResourceIds($wpdb, $winFrom, $winTo);

                // Zjistit dostupné slugy zdrojů pro tento slot
                $allResourceSlugs = $slot->resources ?? ['sud', 'sauna'];
                $freeResourceSlugs = $this->getFreeSlugs($wpdb, $allResourceSlugs, $busyResourceIds);

                $offers = $this->buildOffers($freeResourceSlugs, $tier, $date, $slot->from);

                $availableSlots[] = new AvailabilitySlot(
                    from: $slot->from,
                    to: $slot->to,
                    status: empty($offers) ? 'full' : 'available',
                    offers: $offers,
                );
            }

            $days[] = new AvailabilityDay($date, $policy, $slots, $availableSlots);
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /** @return int[] resource_id obsazených zdrojů */
    private function getBusyResourceIds(\wpdb $wpdb, \DateTimeImmutable $winFrom, \DateTimeImmutable $winTo): array
    {
        $table   = $wpdb->prefix . 'duj_booking_items';
        $tableBk = $wpdb->prefix . 'duj_bookings';
        $nowUtc  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT bi.resource_id
                 FROM `{$table}` bi
                 JOIN `{$tableBk}` b ON b.id = bi.booking_id
                 WHERE bi.blocked_from < %s
                   AND bi.blocked_to   > %s
                   AND bi.blocking_key IS NOT NULL
                   AND (b.status <> 'pending_payment' OR b.hold_expires_at > %s)",
                $winTo->format('Y-m-d H:i:s'),
                $winFrom->format('Y-m-d H:i:s'),
                $nowUtc
            )
        );

        return array_map('intval', $rows ?? []);
    }

    private function getFreeSlugs(\wpdb $wpdb, array $allSlugs, array $busyResourceIds): array
    {
        if (empty($busyResourceIds)) {
            return $allSlugs;
        }

        $table = $wpdb->prefix . 'duj_resources';
        $placeholders = implode(',', array_fill(0, count($busyResourceIds), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $busySlugs = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT slug FROM `{$table}` WHERE id IN ($placeholders)",
                ...$busyResourceIds
            )
        ) ?? [];

        return array_values(array_diff($allSlugs, $busySlugs));
    }

    /** @return PriceOffer[] */
    private function buildOffers(array $freeSlugs, PriceTier $tier, string $date, string $slotFrom): array
    {
        $offers = [];
        $hasSud   = in_array('sud', $freeSlugs, true);
        $hasSauna = in_array('sauna', $freeSlugs, true);

        if ($hasSud) {
            try {
                $price = $this->pricingService->resolvePrice($tier, 'sud', $date, $slotFrom);
                $offers[] = new PriceOffer('sud', $price);
            } catch (\RuntimeException) {
                // cena není nakonfigurována — přeskočit
            }
        }

        if ($hasSauna) {
            try {
                $price = $this->pricingService->resolvePrice($tier, 'sauna', $date, $slotFrom);
                $offers[] = new PriceOffer('sauna', $price);
            } catch (\RuntimeException) {
            }
        }

        // Kombo jen když jsou volné oba zdroje
        if ($hasSud && $hasSauna) {
            try {
                $price = $this->pricingService->resolvePrice($tier, 'sauna+sud', $date, $slotFrom);
                $offers[] = new PriceOffer('sauna+sud', $price);
            } catch (\RuntimeException) {
            }
        }

        return $offers;
    }

    private function toUtc(string $date, string $time, \DateTimeZone $localTz): \DateTimeImmutable
    {
        $timeFull = strlen($time) === 5 ? $time . ':00' : $time;
        return (new \DateTimeImmutable("$date $timeFull", $localTz))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}

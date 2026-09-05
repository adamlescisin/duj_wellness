<?php

declare(strict_types=1);

namespace Duj\Wellness\Accommodation;

use Duj\Wellness\Repository\AccommodationRepositoryInterface;

/**
 * Stahuje a synchronizuje iCal feed ubytování do duj_accommodation_blocks.
 *
 * BEZPEČNOSTNÍ PRAVIDLA:
 * - URL feedu se čte výhradně z konstanty DUJ_ACCOMMODATION_ICS_URL (nikdy z wp_options, nikdy logovat).
 * - stale_policy = 'fail_safe': pokud jsou data stará nebo fetch selže, vrátí se 'closed'.
 * - ManualSource (is_manual=1) má vždy přednost — nikdy nepřepíše manuální bloky.
 * - SUMMARY/DESCRIPTION z iCal se nikdy neukládají — jen v paměti pro klasifikaci.
 */
final class AccommodationSyncService
{
    /** Maximální stáří sync dat v sekundách (fail_safe threshold: 26 hodin). */
    private const STALE_THRESHOLD_SECONDS = 26 * 3600;

    public function __construct(
        private readonly AccommodationRepositoryInterface $repo,
        private readonly IcsParser $parser,
        private readonly AccommodationClassifier $classifier,
    ) {}

    /**
     * Spustí synchronizaci:
     * 1. Stáhne feed z DUJ_ACCOMMODATION_ICS_URL
     * 2. Parsuje iCal
     * 3. Expanduje date ranges na jednotlivé dny
     * 4. Upsertuje do DB (manuální bloky zůstávají nedotčeny)
     * 5. Smaže stará sync data (starší než 2 roky zpět)
     *
     * @return int Počet zpracovaných dnů
     */
    public function sync(): int
    {
        $url = $this->getIcsUrl();
        if ($url === null) {
            error_log('[duj-wellness] AccommodationSyncService: DUJ_ACCOMMODATION_ICS_URL není definována.');
            return 0;
        }

        $content = $this->fetchContent($url);
        if ($content === null) {
            // Fetch selhal — fail_safe: neměníme stávající data
            return 0;
        }

        $events  = $this->parser->parse($content, [$this->classifier, 'classify']);
        $blocks  = $this->expandEvents($events);
        $nowUtc  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $syncedAt = $nowUtc->format('Y-m-d H:i:s');

        if (!empty($blocks)) {
            $this->repo->upsertFromSync($blocks, $syncedAt);
        }

        // Čistíme sync záznamy starší než 2 roky
        $cutoff = $nowUtc->modify('-2 years')->format('Y-m-d');
        $this->repo->deleteSyncedBefore($cutoff);

        return count($blocks);
    }

    /**
     * Zjistí, zda jsou sync data aktuální (fail_safe threshold).
     * Vrací true, pokud je synced_at mladší než STALE_THRESHOLD_SECONDS.
     */
    public function isDataFresh(?\DateTimeImmutable $syncedAt): bool
    {
        if ($syncedAt === null) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $age = $now->getTimestamp() - $syncedAt->getTimestamp();

        return $age <= self::STALE_THRESHOLD_SECONDS;
    }

    /**
     * Vrátí politiku pro datum s ohledem na fail_safe pravidlo.
     * Pokud jsou data stará nebo chybí, vrací 'closed'.
     */
    public function getPolicyForDate(string $date, ?\DateTimeImmutable $syncedAt, ?string $storedPolicy): string
    {
        if (!$this->isDataFresh($syncedAt)) {
            return 'closed'; // fail_safe
        }

        return $storedPolicy ?? 'guests_only';
    }

    private function getIcsUrl(): ?string
    {
        if (!defined('DUJ_ACCOMMODATION_ICS_URL')) {
            return null;
        }

        $url = constant('DUJ_ACCOMMODATION_ICS_URL');

        if (!is_string($url) || $url === '') {
            return null;
        }

        return $url;
        // URL se nikdy nezaloguje
    }

    private function fetchContent(string $url): ?string
    {
        $args = [
            'timeout'    => 15,
            'user-agent' => 'duj-wellness-sync/1.0',
            'sslverify'  => true,
        ];

        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                error_log('[duj-wellness] AccommodationSyncService: fetch selhal.');
                return null;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                error_log('[duj-wellness] AccommodationSyncService: HTTP ' . $code);
                return null;
            }

            return wp_remote_retrieve_body($response);
        }

        // Fallback pro unit testy / CLI — bez WP HTTP API
        $content = @file_get_contents($url); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $content !== false ? $content : null;
    }

    /**
     * Expanduje IcsEvent[] (date ranges) na pole ['block_date' => 'Y-m-d', 'policy' => string].
     * DTEND je exclusive podle RFC 5545, proto iterujeme od dtStart do dtEnd - 1 den.
     *
     * @param IcsEvent[] $events
     * @return array<array{block_date: string, policy: string}>
     */
    private function expandEvents(array $events): array
    {
        $blocks = [];

        foreach ($events as $event) {
            try {
                $start = new \DateTimeImmutable($event->dtStart, new \DateTimeZone('UTC'));
                $end   = new \DateTimeImmutable($event->dtEnd,   new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                continue;
            }

            $current = $start;
            while ($current < $end) {
                $blocks[] = [
                    'block_date' => $current->format('Y-m-d'),
                    'policy'     => $event->policy,
                ];
                $current = $current->modify('+1 day');
            }
        }

        // Pokud jedno datum pokryje více eventů, pozdější event vyhraje (poslední write)
        // Pořadí se zachová, repository upsert přepíše duplicity v pořadí zpracování.
        return $blocks;
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

use Duj\Wellness\Domain\PriceTier;

final class PriceRepository implements PriceRepositoryInterface
{
    public function findAllTiers(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_price_tiers';

        $rows = $wpdb->get_results(
            "SELECT * FROM `{$table}` WHERE is_active = 1 ORDER BY sort_order ASC",
            ARRAY_A
        );

        return array_map([$this, 'hydrateTier'], $rows ?? []);
    }

    public function findTierBySlug(string $slug): ?PriceTier
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_price_tiers';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE slug = %s AND is_active = 1 LIMIT 1",
                $slug
            ),
            ARRAY_A
        );

        return $row ? $this->hydrateTier($row) : null;
    }

    public function findDefaultTier(): ?PriceTier
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_price_tiers';

        $row = $wpdb->get_row(
            "SELECT * FROM `{$table}` WHERE is_default = 1 AND is_active = 1 LIMIT 1",
            ARRAY_A
        );

        return $row ? $this->hydrateTier($row) : null;
    }

    public function resolvePrice(string $tierSlug, string $comboKey, string $date, string $slotFrom): ?PriceRow
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_prices';

        $weekday = (int) (new \DateTimeImmutable($date, new \DateTimeZone('Europe/Prague')))->format('N');
        $slotFromTime = strlen($slotFrom) === 5 ? $slotFrom . ':00' : $slotFrom;

        // Vybere aktivní záznam s nejvyšší prioritou, který matchuje podmínky.
        // NULL weekday / NULL time_from = platí pro všechny.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE tier_slug = %s
                   AND combo_key = %s
                   AND is_active = 1
                   AND (weekday  IS NULL OR weekday  = %d)
                   AND (time_from IS NULL OR time_from = %s)
                   AND (valid_from IS NULL OR valid_from <= %s)
                   AND (valid_to   IS NULL OR valid_to   >= %s)
                 ORDER BY priority DESC
                 LIMIT 1",
                $tierSlug,
                $comboKey,
                $weekday,
                $slotFromTime,
                $date,
                $date
            ),
            ARRAY_A
        );

        return $row ? $this->hydratePrice($row) : null;
    }

    public function findAllPrices(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'duj_prices';

        $rows = $wpdb->get_results(
            "SELECT * FROM `{$table}` WHERE is_active = 1 ORDER BY tier_slug, combo_key, priority DESC",
            ARRAY_A
        );

        return array_map([$this, 'hydratePrice'], $rows ?? []);
    }

    private function hydrateTier(array $row): PriceTier
    {
        return new PriceTier(
            slug: $row['slug'],
            label: $row['label'],
            isDefault: (bool) $row['is_default'],
            requiresCode: (bool) $row['requires_code'],
            showInForm: (bool) $row['show_in_form'],
            cutoffMode: $row['cutoff_mode'],
            minLeadMinutes: isset($row['min_lead_minutes']) ? (int) $row['min_lead_minutes'] : null,
            sortOrder: (int) $row['sort_order'],
            isActive: (bool) $row['is_active'],
        );
    }

    private function hydratePrice(array $row): PriceRow
    {
        return new PriceRow(
            id: (int) $row['id'],
            tierSlug: $row['tier_slug'],
            comboKey: $row['combo_key'],
            label: $row['label'],
            amountMinor: (int) $row['amount_minor'],
            currency: $row['currency'],
            weekday: isset($row['weekday']) ? (int) $row['weekday'] : null,
            timeFrom: $row['time_from'] ?? null,
            validFrom: $row['valid_from'] ?? null,
            validTo: $row['valid_to'] ?? null,
            priority: (int) $row['priority'],
            isActive: (bool) $row['is_active'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Accommodation;

/**
 * Klasifikuje iCal událost na politiku dne.
 * SUMMARY a DESCRIPTION jsou používány pouze v paměti — nikdy neukládány ani nezalogovány.
 */
final class AccommodationClassifier
{
    /**
     * @param string $summary     Obsah SUMMARY z iCal (jen v paměti)
     * @param string $description Obsah DESCRIPTION z iCal (jen v paměti)
     * @return string 'guests_only' | 'closed'
     */
    public function classify(string $summary, string $description): string
    {
        // Hledáme klíčová slova indikující, že domeček je zavřen úplně
        $text = mb_strtolower($summary . ' ' . $description, 'UTF-8');

        $closedKeywords = ['zavřeno', 'closed', 'maintenance', 'údržba', 'oprava', 'servis'];
        foreach ($closedKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return 'closed';
            }
        }

        // Výchozí: dny s ubytováním jsou pro hosty
        return 'guests_only';
    }
}

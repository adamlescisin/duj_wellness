<?php

declare(strict_types=1);

namespace Duj\Wellness\Accommodation;

/**
 * Parser iCal feedu pro ubytování.
 *
 * BEZPEČNOSTNÍ PRAVIDLO: SUMMARY a DESCRIPTION se NIKDY neukládají ani nezalogují.
 * Používají se jen v paměti pro klasifikaci politiky bloku.
 *
 * Implementuje:
 * - RFC 5545 line folding (CRLF + mezera)
 * - DTSTART/DTEND v DATE i DATE-TIME formátu
 * - DTEND je exclusive (iCal standard)
 */
final class IcsParser
{
    /**
     * @param  string $icsContent Obsah iCal feedu (surový text)
     * @param  callable(string $summary, string $description): string $classifier
     *         Funkce pro klasifikaci události — vrátí 'guests_only' nebo 'closed'.
     *         Argumenty se předávají jen v paměti, NIKDY se nelogují.
     * @return IcsEvent[]
     */
    public function parse(string $icsContent, callable $classifier): array
    {
        // RFC 5545: unfold continuation lines (CRLF + space nebo tab)
        $unfolded = preg_replace("/\r\n[ \t]/", '', $icsContent);

        $events  = [];
        $inEvent = false;
        $current = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $unfolded ?? $icsContent)) as $line) {
            $line = rtrim($line);

            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $current = ['summary' => '', 'description' => '', 'dtstart' => '', 'dtend' => ''];
                continue;
            }

            if ($line === 'END:VEVENT') {
                $inEvent = false;

                if ($current['dtstart'] === '' || $current['dtend'] === '') {
                    $current = [];
                    continue;
                }

                // Klasifikace v paměti — SUMMARY/DESCRIPTION nikdy neukládáme
                $policy = $classifier($current['summary'], $current['description']);

                $events[] = new IcsEvent(
                    dtStart: $current['dtstart'],
                    dtEnd:   $current['dtend'],
                    policy:  in_array($policy, ['guests_only', 'closed'], true) ? $policy : 'guests_only',
                );

                $current = [];
                continue;
            }

            if (!$inEvent) {
                continue;
            }

            // Parsování property (může mít parametry: DTSTART;TZID=Europe/Prague:...)
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $name  = strtoupper(substr($line, 0, $colonPos));
            $value = substr($line, $colonPos + 1);

            // Ořízni parametry (DTSTART;TZID=... → DTSTART)
            $semicolonPos = strpos($name, ';');
            $baseName = $semicolonPos !== false ? substr($name, 0, $semicolonPos) : $name;

            match ($baseName) {
                'DTSTART'     => $current['dtstart']     = $this->parseDate($value),
                'DTEND'       => $current['dtend']       = $this->parseDate($value),
                'SUMMARY'     => $current['summary']     = $value,
                'DESCRIPTION' => $current['description'] = $value,
                default       => null,
            };
        }

        return $events;
    }

    /**
     * Převede iCal DATE nebo DATE-TIME na 'Y-m-d'.
     * DTEND je exclusive dle RFC 5545 — vrací tak jak je (exclusive handling je v SyncService).
     */
    private function parseDate(string $value): string
    {
        // DATE-TIME: 20250101T160000Z nebo 20250101T160000
        if (strlen($value) >= 15 && str_contains($value, 'T')) {
            $dateStr = substr($value, 0, 8);
            return sprintf(
                '%s-%s-%s',
                substr($dateStr, 0, 4),
                substr($dateStr, 4, 2),
                substr($dateStr, 6, 2)
            );
        }

        // DATE: 20250101
        if (strlen($value) === 8) {
            return sprintf(
                '%s-%s-%s',
                substr($value, 0, 4),
                substr($value, 4, 2),
                substr($value, 6, 2)
            );
        }

        return $value;
    }
}

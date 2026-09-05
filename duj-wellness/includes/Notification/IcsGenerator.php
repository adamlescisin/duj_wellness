<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification;

use Duj\Wellness\Repository\BookingRow;

/**
 * Generuje .ics soubor (iCalendar) pro potvrzenou rezervaci.
 * Zákazník si může termín přidat do svého kalendáře.
 */
final class IcsGenerator
{
    /**
     * Vygeneruje .ics obsah pro rezervaci.
     * Čas je v časové zóně Europe/Prague.
     */
    public function forBooking(BookingRow $booking, string $siteName, string $siteUrl): string
    {
        $tz        = new \DateTimeZone('Europe/Prague');
        $dtStart   = new \DateTimeImmutable("{$booking->bookingDate} {$booking->slotFrom}", $tz);
        $dtEnd     = new \DateTimeImmutable("{$booking->bookingDate} {$booking->slotTo}", $tz);
        $uid       = $booking->uuid . '@' . parse_url($siteUrl, PHP_URL_HOST);
        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $summary   = $this->icsEscape($siteName . ' · ' . $booking->reference);
        $location  = function_exists('get_option') ? $this->icsEscape((string) get_option('duj_address', 'Domeček u Josefa')) : 'Domeček u Josefa';

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//DUJ Wellness//Rezervacni system//CS',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->format('Ymd\THis\Z'),
            'DTSTART;TZID=Europe/Prague:' . $dtStart->format('Ymd\THis'),
            'DTEND;TZID=Europe/Prague:' . $dtEnd->format('Ymd\THis'),
            'SUMMARY:' . $summary,
            'LOCATION:' . $location,
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    /** Escapuje speciální znaky v iCal hodnotách. */
    private function icsEscape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }
}

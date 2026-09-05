<?php

declare(strict_types=1);

namespace Duj\Wellness\Accommodation;

/**
 * Parsed iCal event — SUMMARY a DESCRIPTION záměrně vynechány.
 * Nikdy je neukládáme ani nezalogujeme — jen v paměti pro klasifikaci.
 */
final class IcsEvent
{
    public function __construct(
        /** Začátek bloku (DATE nebo DATETIME, inclusive) */
        public readonly string $dtStart,
        /** Konec bloku (DATE nebo DATETIME, exclusive — dle iCal RFC) */
        public readonly string $dtEnd,
        /** Klasifikace v paměti — 'guests_only' | 'closed' */
        public readonly string $policy,
    ) {}
}

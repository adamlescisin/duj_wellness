<?php

declare(strict_types=1);

namespace Duj\Wellness\Domain;

/**
 * Normalizace combo klíče — slugy vždy abecedně, spojené '+'.
 * 'sauna' < 'sud' (a < u), takže správný tvar je vždy 'sauna+sud'.
 */
final class ComboKey
{
    public const VALID_KEYS = ['sud', 'sauna', 'sauna+sud'];

    public static function normalize(string $raw): string
    {
        $parts = array_filter(
            array_map('trim', explode('+', strtolower($raw))),
            static fn(string $p): bool => $p !== ''
        );
        sort($parts);
        return implode('+', $parts);
    }

    public static function isValid(string $key): bool
    {
        return in_array(self::normalize($key), self::VALID_KEYS, true);
    }

    /** Vrátí slugy zdrojů pro daný combo key. */
    public static function toResourceSlugs(string $comboKey): array
    {
        return explode('+', self::normalize($comboKey));
    }

    /** Vrátí čitelný popis kombinace. */
    public static function toLabel(string $comboKey): string
    {
        return match (self::normalize($comboKey)) {
            'sud'       => __('Koupací sud', 'duj-wellness'),
            'sauna'     => __('Sauna', 'duj-wellness'),
            'sauna+sud' => __('Sauna i koupací sud (kombo)', 'duj-wellness'),
            default     => $comboKey,
        };
    }
}

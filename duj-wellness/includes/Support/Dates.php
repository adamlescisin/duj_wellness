<?php

declare(strict_types=1);

namespace Duj\Wellness\Support;

final class Dates
{
    public const TIMEZONE = 'Europe/Prague';

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function parse(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone(self::TIMEZONE));
    }

    public static function toUtc(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}

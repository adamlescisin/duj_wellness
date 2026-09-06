<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface AccommodationRepositoryInterface
{
    public function findBlockForDate(string $date): ?AccommodationBlock;

    /** @return AccommodationBlock[] */
    public function findBlocksInRange(string $from, string $to): array;

    /**
     * Upsertuje bloky ze synchronizace iCal feedu.
     * Nikdy nepřepisuje řádky, kde is_manual = 1 (manuální zdroj má přednost).
     *
     * @param array<array{block_date: string, policy: string}> $blocks
     * @param string $syncedAt  UTC timestamp 'Y-m-d H:i:s'
     */
    public function upsertFromSync(array $blocks, string $syncedAt): void;

    /**
     * Nastaví nebo aktualizuje manuální blok pro datum.
     * Manuální bloky mají is_manual = 1 a nelze je přepsat syncem.
     */
    public function setManualBlock(string $date, string $policy): void;

    /**
     * Odstraní manuální override pro datum (vrátí se k sync datům).
     */
    public function removeManualBlock(string $date): void;

    /**
     * Smaže všechny sync řádky starší než $before (pro čištění starých dat).
     */
    public function deleteSyncedBefore(string $before): void;
}

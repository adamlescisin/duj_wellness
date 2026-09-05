<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface AccessCodeRepositoryInterface
{
    /** Najde aktivní kód platný pro dané datum. Vrátí null pokud neexistuje nebo nesplňuje podmínky. */
    public function findActiveCode(string $code, string $date): ?AccessCodeRow;

    /** Inkrementuje used_count — volat jen při přechodu do awaiting_confirmation. */
    public function incrementUsedCount(string $code): void;
}

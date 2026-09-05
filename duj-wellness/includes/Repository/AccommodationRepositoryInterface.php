<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface AccommodationRepositoryInterface
{
    public function findBlockForDate(string $date): ?AccommodationBlock;

    /** @return AccommodationBlock[] */
    public function findBlocksInRange(string $from, string $to): array;
}

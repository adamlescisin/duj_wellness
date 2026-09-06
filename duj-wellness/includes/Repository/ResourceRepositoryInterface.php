<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface ResourceRepositoryInterface
{
    /** @return int[] Resource IDs seřazené vzestupně */
    public function findIdsBySlugs(array $slugs): array;
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Repository;

interface DayLockRepositoryInterface
{
    public function lockRows(string $date, array $resourceIds): void;
}

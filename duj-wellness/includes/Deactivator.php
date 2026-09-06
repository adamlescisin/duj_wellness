<?php

declare(strict_types=1);

namespace Duj\Wellness;

use Duj\Wellness\Cron\ExpireHoldsJob;
use Duj\Wellness\Cron\RetentionCleanupJob;
use Duj\Wellness\Cron\SyncAccommodationJob;

final class Deactivator
{
    public static function deactivate(): void
    {
        ExpireHoldsJob::unschedule();
        SyncAccommodationJob::unschedule();
        RetentionCleanupJob::unschedule();
        flush_rewrite_rules();
    }
}

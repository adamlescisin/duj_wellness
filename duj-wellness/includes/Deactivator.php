<?php

declare(strict_types=1);

namespace Duj\Wellness;

final class Deactivator
{
    public static function deactivate(): void
    {
        flush_rewrite_rules();
        // Action Scheduler jobs se čistí přes uninstall, ne deaktivaci.
    }
}

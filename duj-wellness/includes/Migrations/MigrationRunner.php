<?php

declare(strict_types=1);

namespace Duj\Wellness\Migrations;

/**
 * Spouští migrace v pořadí, aktuální verzi ukládá jako WP option.
 */
final class MigrationRunner
{
    private const OPTION_KEY = 'duj_db_version';

    /** @var MigrationInterface[] */
    private array $migrations = [];

    public function register(MigrationInterface $migration): void
    {
        $this->migrations[] = $migration;
    }

    public function run(): void
    {
        $current = (int) get_option(self::OPTION_KEY, 0);

        foreach ($this->migrations as $migration) {
            if ($migration->version() > $current) {
                $migration->up();
                $current = $migration->version();
                update_option(self::OPTION_KEY, $current, false);
            }
        }
    }

    public function currentVersion(): int
    {
        return (int) get_option(self::OPTION_KEY, 0);
    }
}

<?php

declare(strict_types=1);

namespace Duj\Wellness\Migrations;

interface MigrationInterface
{
    public function version(): int;

    public function up(): void;
}

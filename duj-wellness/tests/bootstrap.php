<?php

declare(strict_types=1);

// Unit testy nevyžadují WordPress — testujeme čistou PHP logiku.
// Integrační testy (s WP a DB) budou v tests/Integration/.

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Stub pro wpdb — umožňuje mockování v unit testech bez WP
if (!class_exists('wpdb')) {
    class wpdb // phpcs:ignore
    {
        public string $prefix = 'wp_';

        public function prepare(string $query, mixed ...$args): string { return $query; }
        public function get_var(?string $query = null): mixed { return null; }
        public function get_row(?string $query = null, string $output = OBJECT): mixed { return null; }
        public function get_col(?string $query = null): array { return []; }
        public function get_results(?string $query = null, string $output = OBJECT): array { return []; }
        public function query(string $query): int|bool { return true; }
        public function insert(string $table, array $data, mixed $format = null): int|bool { return 1; }
        public function update(string $table, array $data, array $where, mixed $format = null, mixed $whereFormat = null): int|bool { return 1; }
        public int $insert_id = 1;
    }
}

// Stub pro WordPress globální funkce
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}

if (!function_exists('number_format')) {
    // PHP built-in — already exists, just in case
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

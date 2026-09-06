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
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// WP transient stubs — in-memory, for webhook idempotency tests
if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['_duj_transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['_duj_transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['_duj_transients'][$key]);
        return true;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string { return 'https://example.com' . $path; }
}
if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed { return $default; }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string { return preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? $filename; }
}
if (!function_exists('wp_mail')) {
    function wp_mail(string $to, string $subject, string $message, array $headers = [], array $attachments = []): bool { return true; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string {
        $query = http_build_query($args);
        $sep   = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . $query;
    }
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

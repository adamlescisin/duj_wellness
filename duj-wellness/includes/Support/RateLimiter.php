<?php

declare(strict_types=1);

namespace Duj\Wellness\Support;

/**
 * Jednoduchý rate limiter pomocí WordPress transients.
 * Klíč: endpoint + IP (jako hex z inet_pton, nikdy plaintext v logu).
 */
final class RateLimiter
{
    public function __construct(
        private readonly int $maxAttempts = 10,
        private readonly int $windowSeconds = 60,
    ) {}

    public function check(string $endpoint, string $ip): bool
    {
        $key   = $this->buildKey($endpoint, $ip);
        $count = (int) get_transient($key);

        if ($count >= $this->maxAttempts) {
            return false;
        }

        if ($count === 0) {
            set_transient($key, 1, $this->windowSeconds);
        } else {
            set_transient($key, $count + 1, $this->windowSeconds);
        }

        return true;
    }

    private function buildKey(string $endpoint, string $ip): string
    {
        $ipBin = inet_pton($ip);
        $ipHex = $ipBin !== false ? bin2hex($ipBin) : hash('sha256', $ip);
        return 'duj_rl_' . substr(hash('sha256', $endpoint . $ipHex), 0, 24);
    }
}

<?php

namespace App\Pixelforge;

/**
 * Pixelforge-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">,
 * keyed with the API key's signing secret.
 */
final class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function verify(string $header, string $payload, ?int $now = null): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($this->secret === '' || ! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        return hash_equals($expected, $signature);
    }

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}

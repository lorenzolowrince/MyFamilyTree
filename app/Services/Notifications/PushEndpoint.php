<?php

namespace App\Services\Notifications;

/**
 * Checks the address a browser gives for its push subscription.
 *
 * The server later sends requests to that address, so an address chosen by an attacker
 * could make it call internal services (SSRF). Only HTTPS addresses at the browsers' own
 * push services are accepted (config/webpush.php, `allowed_hosts`).
 */
class PushEndpoint
{
    public const MAX_LENGTH = 500;

    public static function isAllowed(string $endpoint): bool
    {
        if (strlen($endpoint) > self::MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($endpoint);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        /** @var list<string> $allowed */
        $allowed = config('webpush.allowed_hosts', []);

        foreach ($allowed as $pattern) {
            $pattern = strtolower($pattern);

            // "*.example.com" allows any subdomain of example.com, not example.com itself.
            if (str_starts_with($pattern, '*.')
                ? str_ends_with($host, substr($pattern, 1))
                : $host === $pattern) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Support;

/**
 * Turns a browser's User-Agent header into a short label such as "Chrome on Windows".
 * Deliberately small: it only needs to help someone recognise their own devices.
 */
final class UserAgent
{
    private const BROWSERS = [
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'FxiOS' => 'Firefox',
        'CriOS' => 'Chrome',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    private const PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh' => 'Mac',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function describe(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = self::match(self::BROWSERS, $userAgent);
        $platform = self::match(self::PLATFORMS, $userAgent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    /**
     * @param  array<string, string>  $needles  token => label, most specific first
     */
    private static function match(array $needles, string $userAgent): ?string
    {
        foreach ($needles as $token => $label) {
            if (str_contains($userAgent, $token)) {
                return $label;
            }
        }

        return null;
    }
}

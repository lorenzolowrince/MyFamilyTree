<?php

namespace App\Services\Gedcom;

use App\Enums\DatePrecision;
use App\Enums\DateQualifier;
use App\Support\FuzzyDate\FuzzyDate;

/**
 * Converts between our fuzzy dates and GEDCOM date values
 * (`15 JUL 1998`, `ABT 1920`, `BEF 1945`, `BET 1890 AND 1895`, date phrases).
 */
final class GedcomDate
{
    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    private const PREFIXES = [
        'about' => 'ABT', 'estimated' => 'EST', 'calculated' => 'CAL', 'before' => 'BEF', 'after' => 'AFT',
    ];

    /**
     * @return array{0: string, 1: string|null} the DATE value and its PHRASE
     */
    public static function write(FuzzyDate $date): array
    {
        if ($date->date === null) {
            return ['', $date->text];
        }

        $year = (string) $date->date->year;

        if ($date->precision === DatePrecision::Decade) {
            // GEDCOM has no "decade": written as a range, marked so that we can read it back.
            return ["BET {$year} AND ".($date->date->year + 9), "{$year}s".($date->text === null ? '' : "; {$date->text}")];
        }

        $base = match ($date->precision) {
            DatePrecision::Day => $date->date->day.' '.self::MONTHS[$date->date->month - 1].' '.$year,
            DatePrecision::Month => self::MONTHS[$date->date->month - 1].' '.$year,
            default => $year,
        };

        $value = match (true) {
            $date->qualifier === DateQualifier::Between && $date->dateTo !== null => "BET {$base} AND {$date->dateTo->year}",
            isset(self::PREFIXES[$date->qualifier->value]) => self::PREFIXES[$date->qualifier->value].' '.$base,
            default => $base,
        };

        return [$value, $date->text];
    }

    /**
     * Read a GEDCOM date into the shape FuzzyDate::fromInput() takes. A value that cannot be
     * understood is kept word for word as the date's text, so nothing is lost.
     *
     * @return array{year: int|null, month: int|null, day: int|null, qualifier: string, year_to: int|null, decade: bool, text: string|null}
     */
    public static function read(?string $value, ?string $phrase = null): array
    {
        $empty = ['year' => null, 'month' => null, 'day' => null, 'qualifier' => 'exact', 'year_to' => null, 'decade' => false, 'text' => null];
        $phrase = $phrase === null || trim($phrase) === '' ? null : trim($phrase);
        $raw = trim((string) $value);

        // Calendar markers; only the Gregorian reading is kept.
        $text = strtoupper(trim((string) preg_replace('/@#D[A-Z ]+@|\b(GREGORIAN|JULIAN)\b/i', '', $raw)));

        if ($text === '') {
            return [...$empty, 'text' => $phrase];
        }

        // 5.5.1: "(some words)" is a phrase; "INT 1920 (words)" a date with a phrase.
        if (preg_match('/^\((.*)\)$/s', $raw, $match) === 1) {
            return [...$empty, 'text' => trim($match[1]) ?: $phrase];
        }

        if (preg_match('/^INT (.+?) \((.*)\)$/s', $text, $match) === 1) {
            $text = $match[1];
            $phrase ??= trim(substr($raw, (int) strpos($raw, '(') + 1, -1));
        }

        if (preg_match('/^(?:BET|FROM) (.+?) (?:AND|TO) (.+)$/', $text, $match) === 1) {
            $from = self::simple($match[1]);
            $to = self::simple($match[2]);

            if ($from !== null && $to !== null) {
                $decade = $from['year'] % 10 === 0 && $to['year'] === $from['year'] + 9
                    && $phrase !== null && str_starts_with($phrase, "{$from['year']}s");

                if ($decade) {
                    $rest = trim(substr((string) $phrase, strlen("{$from['year']}s")), '; ');

                    return [...$empty, 'year' => $from['year'], 'decade' => true, 'text' => $rest === '' ? null : $rest];
                }

                return [...$empty, ...$from, 'qualifier' => 'between', 'year_to' => $to['year'], 'text' => $phrase];
            }
        }

        foreach ([...array_flip(self::PREFIXES), 'TO' => 'before', 'FROM' => 'after'] as $prefix => $qualifier) {
            if (str_starts_with($text, $prefix.' ')) {
                $date = self::simple(substr($text, strlen($prefix) + 1));

                if ($date !== null) {
                    return [...$empty, ...$date, 'qualifier' => $qualifier, 'text' => $phrase];
                }
            }
        }

        $date = self::simple($text);

        return $date === null
            ? [...$empty, 'text' => $phrase ?? $raw]
            : [...$empty, ...$date, 'text' => $phrase];
    }

    /**
     * "15 JUL 1998", "JUL 1998", "1998", "1750/51".
     *
     * @return array{year: int, month: int|null, day: int|null}|null
     */
    private static function simple(string $text): ?array
    {
        if (preg_match('/^(?:(\d{1,2}) )?(?:([A-Z]{3}) )?(\d{1,4})(?:\/\d{2})?$/', trim($text), $match) !== 1) {
            return null;
        }

        $year = (int) $match[3];
        $month = $match[2] === '' ? null : array_search($match[2], self::MONTHS, true);
        $day = $match[1] === '' ? null : (int) $match[1];

        if ($year < 1 || $year > (int) date('Y') || $month === false || ($day !== null && ($month === null || ! checkdate($month + 1, $day, $year)))) {
            return null;
        }

        return ['year' => $year, 'month' => $month === null ? null : $month + 1, 'day' => $day];
    }
}

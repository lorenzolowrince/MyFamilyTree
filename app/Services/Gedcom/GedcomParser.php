<?php

namespace App\Services\Gedcom;

/**
 * Reads GEDCOM text into a tree of lines. Tolerant on purpose: files written by other
 * programs are often not quite standard, so a line that cannot be read becomes a warning
 * rather than a failure. Understands GEDCOM 7 and the older 5.5.1 (`CONC`, ANSI text).
 *
 * @phpstan-type Node array{tag: string, xref: string|null, value: string, line: int, children: list<array<string, mixed>>}
 */
class GedcomParser
{
    public const MAX_WARNINGS = 200;

    /**
     * @return array{records: list<Node>, warnings: list<string>}
     */
    public function parse(string $text): array
    {
        $warnings = [];
        $text = $this->toUtf8($text, $warnings);

        /** @var list<Node> $records */
        $records = [];
        /** @var array<int, array<string, mixed>> $open the node currently open at each level */
        $open = [];
        $lastLevel = -1;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $raw) {
            $number = $index + 1;
            $line = ltrim($raw, " \t");

            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^(\d{1,2}) (?:(@[^@ ]+@) )?([A-Za-z0-9_]+)(?: (.*))?$/su', $line, $match) !== 1) {
                self::warn($warnings, __('Line :n could not be read and was skipped.', ['n' => $number]));

                continue;
            }

            $level = (int) $match[1];
            $tag = strtoupper($match[3]);
            $value = $match[4] ?? '';

            // "@@" at the start of a value stands for a single "@".
            if (str_starts_with($value, '@@')) {
                $value = substr($value, 1);
            }

            if ($level > $lastLevel + 1) {
                self::warn($warnings, __('Line :n is indented too far and was skipped.', ['n' => $number]));

                continue;
            }

            // Continuation lines belong to the value of the line above.
            if (($tag === 'CONT' || $tag === 'CONC') && $level > 0 && isset($open[$level - 1])) {
                $open[$level - 1]['value'] .= ($tag === 'CONT' ? "\n" : '').$value;

                continue;
            }

            $node = ['tag' => $tag, 'xref' => $match[2] === '' ? null : $match[2], 'value' => $value, 'line' => $number, 'children' => []];

            if ($level === 0) {
                $records[] = $node;
                $open = [0 => &$records[count($records) - 1]];
            } else {
                $parent = &$open[$level - 1];
                $parent['children'][] = $node;
                $open[$level] = &$parent['children'][count($parent['children']) - 1];
                unset($parent);

                // Deeper levels of the previous branch are closed.
                foreach (array_keys($open) as $depth) {
                    if ($depth > $level) {
                        unset($open[$depth]);
                    }
                }
            }

            $lastLevel = $level;
        }

        unset($open);

        if ($records === [] || $records[0]['tag'] !== 'HEAD') {
            self::warn($warnings, __('The file does not start with a GEDCOM header. It may not be a GEDCOM file.'));
        }

        return ['records' => $records, 'warnings' => $warnings];
    }

    /**
     * The first child with this tag.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    public static function child(array $node, string $tag): ?array
    {
        foreach ($node['children'] as $child) {
            if ($child['tag'] === $tag) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    public static function children(array $node, string $tag): array
    {
        return array_values(array_filter($node['children'], fn (array $child): bool => $child['tag'] === $tag));
    }

    /**
     * The trimmed value of the first child with this tag, or null when missing or empty.
     *
     * @param  array<string, mixed>  $node
     */
    public static function value(array $node, string $tag): ?string
    {
        $value = trim((string) (self::child($node, $tag)['value'] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $warnings
     */
    public static function warn(array &$warnings, string $message): void
    {
        if (count($warnings) < self::MAX_WARNINGS) {
            $warnings[] = $message;
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function toUtf8(string $text, array &$warnings): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        } elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }

        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        if (preg_match('/^1 CHAR ANSEL/mi', $text) === 1) {
            self::warn($warnings, __('The file uses the old ANSEL character set. Letters with accents may be wrong; exporting it again as UTF-8 from the other program avoids this.'));
        } else {
            self::warn($warnings, __('The file is not UTF-8. It was read as Western European text; check names with accents.'));
        }

        return (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}

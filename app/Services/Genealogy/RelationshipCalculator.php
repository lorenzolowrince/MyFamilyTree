<?php

namespace App\Services\Genealogy;

/**
 * Works out how two people in a tree are related
 * (docs/architecture/03-relationship-modelling.md, "Relationship Finder").
 *
 * Pure PHP over a FamilyGraph: no database, no language. Every answer is a structure
 * (`kind`, generations up and down, cousin degree, half, adoptive or step, the path of people)
 * read from the first person's point of view: "the second person is the first person's ...".
 * RelationshipLabeler turns it into words.
 *
 * Order of search:
 *  1. descent (birth and adoption); step, foster and guardian links only if that finds nothing;
 *  2. marriage: partner, partner's relatives, relatives' partners;
 *  3. any chain of links at all;
 *  4. not connected.
 *
 * @phpstan-type Step array{id: string, via: string|null, subtype: string|null}
 * @phpstan-type Relation array{kind: string, up: int, down: int, degree: int, removed: int, half: bool, qualifier: string|null, uncertain: bool, former: bool, subtype: string|null, form: string|null, base: array<string, mixed>|null, path: list<Step>, apexes: list<string>}
 * @phpstan-type Line list<array{id: string, subtype: string|null, certainty: string|null}>
 */
class RelationshipCalculator
{
    /** Parent links that make someone a relative by descent. */
    private const LINEAGE = ['biological', 'adoptive', 'unknown'];

    private const MAX_GENERATIONS = 24;

    private const MAX_LINES = 4000;

    public const MAX_RESULTS = 4;

    /**
     * Every distinct way `$y` is related to `$x`, closest first. Never empty: the last resort
     * is a single `unrelated` answer.
     *
     * @return list<Relation>
     */
    public function between(FamilyGraph $graph, string $x, string $y): array
    {
        if (! $graph->has($x) || ! $graph->has($y)) {
            return [self::relation('unrelated')];
        }

        if ($x === $y) {
            return [self::relation('self', path: [['id' => $x, 'via' => null, 'subtype' => null]])];
        }

        $found = [];
        $partner = $this->partner($graph, $x, $y);

        if ($partner !== null) {
            $found[] = $partner;
        }

        $descent = $this->byDescent($graph, $x, $y, lineageOnly: true);

        if ($descent === []) {
            $descent = $this->byDescent($graph, $x, $y, lineageOnly: false);
        }

        $found = [...$found, ...$descent];

        if ($found === []) {
            $marriage = $this->byMarriage($graph, $x, $y);

            if ($marriage !== null) {
                $found[] = $marriage;
            }
        }

        if ($found === []) {
            $chain = $this->anyChain($graph, $x, $y);
            $found[] = $chain === null ? self::relation('unrelated') : self::relation('connected', path: $chain);
        }

        return array_slice($found, 0, self::MAX_RESULTS);
    }

    /**
     * Relationships through shared ancestors (or one being the other's ancestor).
     *
     * @return list<Relation>
     */
    private function byDescent(FamilyGraph $graph, string $x, string $y, bool $lineageOnly): array
    {
        $fromY = [];

        foreach ($this->linesUp($graph, $y, $lineageOnly) as $line) {
            $fromY[$line[count($line) - 1]['id']][] = $line;
        }

        /** @var array<string, array{lines: array{0: Line, 1: Line}, apexes: array<string, true>}> $groups */
        $groups = [];

        foreach ($this->linesUp($graph, $x, $lineageOnly) as $up) {
            $apex = $up[count($up) - 1]['id'];

            foreach ($fromY[$apex] ?? [] as $down) {
                if (count($up) === 1 && count($down) === 1) {
                    continue;
                }

                $below = array_column(array_slice($up, 0, -1), 'id');
                $other = array_column(array_slice($down, 0, -1), 'id');

                // The two lines must meet only at the top, or the meeting point is lower down.
                if (array_intersect($below, $other) !== []) {
                    continue;
                }

                // Lines that differ only in which of a couple is at the top are one relationship.
                $key = implode('>', $below).'|'.implode('>', $other);
                $groups[$key] ??= ['lines' => [$up, $down], 'apexes' => []];
                $groups[$key]['apexes'][$apex] = true;
            }
        }

        $relations = [];

        foreach ($groups as $group) {
            [$up, $down] = $group['lines'];
            $dx = count($up) - 1;
            $dy = count($down) - 1;
            $steps = [...array_slice($up, 1), ...array_slice($down, 1)];
            $subtypes = array_column($steps, 'subtype');

            $half = false;

            if ($dx >= 1 && $dy >= 1 && count($group['apexes']) === 1) {
                $mine = $this->lineageParents($graph, $up[$dx - 1]['id']);
                $theirs = $this->lineageParents($graph, $down[$dy - 1]['id']);
                // "Half" needs both second parents to be known and different; an unrecorded
                // parent proves nothing.
                $half = count($mine) >= 2 && count($theirs) >= 2 && count(array_intersect($mine, $theirs)) === 1;
            }

            $relations[] = self::relation(
                kind: match (true) {
                    $dx === 0 => 'descendant',
                    $dy === 0 => 'ancestor',
                    $dx === 1 && $dy === 1 => 'sibling',
                    $dx === 1 => 'niece_nephew',
                    $dy === 1 => 'uncle_aunt',
                    default => 'cousin',
                },
                up: $dx,
                down: $dy,
                degree: $dx >= 2 && $dy >= 2 ? min($dx, $dy) - 1 : 0,
                removed: $dx >= 2 && $dy >= 2 ? abs($dx - $dy) : 0,
                half: $half,
                qualifier: self::qualifier($subtypes),
                uncertain: array_intersect(array_column($steps, 'certainty'), ['possible', 'disputed']) !== [],
                path: self::pathThrough($up, $down),
                apexes: array_keys($group['apexes']),
            );
        }

        usort($relations, fn (array $a, array $b): int => [$a['up'] + $a['down'], $a['qualifier'] !== null, $a['half'], max($a['up'], $a['down'])]
            <=> [$b['up'] + $b['down'], $b['qualifier'] !== null, $b['half'], max($b['up'], $b['down'])]);

        // One answer per distinct relationship: the same words reached by another route add nothing.
        $distinct = [];

        foreach ($relations as $relation) {
            $distinct[implode('/', [$relation['kind'], $relation['up'], $relation['down'], (int) $relation['half'], $relation['qualifier']])] ??= $relation;
        }

        return array_values($distinct);
    }

    /**
     * @return Relation|null
     */
    private function partner(FamilyGraph $graph, string $x, string $y): ?array
    {
        $links = array_values(array_filter($graph->partnerLinks($x), fn (array $link): bool => $link['person'] === $y));

        if ($links === []) {
            return null;
        }

        // A current partnership describes two people better than an earlier one that ended.
        usort($links, fn (array $a, array $b): int => self::ended($a['status']) <=> self::ended($b['status']));

        return self::relation(
            'partner',
            former: self::ended($links[0]['status']),
            subtype: $links[0]['subtype'],
            path: [
                ['id' => $x, 'via' => null, 'subtype' => null],
                ['id' => $y, 'via' => 'partner', 'subtype' => $links[0]['subtype']],
            ],
        );
    }

    /**
     * Relationships by marriage: a partner's relative, a relative's partner, or both.
     *
     * @return Relation|null
     */
    private function byMarriage(FamilyGraph $graph, string $x, string $y): ?array
    {
        $candidates = [];

        $descent = function (string $from, string $to) use ($graph): ?array {
            if ($from === $to) {
                return null;
            }

            return $this->byDescent($graph, $from, $to, true)[0] ?? $this->byDescent($graph, $from, $to, false)[0] ?? null;
        };

        $step = fn (array $link): array => ['id' => $link['person'], 'via' => 'partner', 'subtype' => $link['subtype']];

        foreach ($graph->partnerLinks($x) as $mine) {
            $base = $descent($mine['person'], $y);

            if ($base !== null) {
                $path = [['id' => $x, 'via' => null, 'subtype' => null], $step($mine), ...array_slice($base['path'], 1)];
                $candidates[] = [0, self::relation('in_law', former: self::ended($mine['status']), form: 'relative_of_partner', base: $base, path: $path)];
            }
        }

        foreach ($graph->partnerLinks($y) as $theirs) {
            $base = $descent($x, $theirs['person']);

            if ($base !== null) {
                $path = [...$base['path'], ['id' => $y, 'via' => 'partner', 'subtype' => $theirs['subtype']]];
                $candidates[] = [1, self::relation('in_law', former: self::ended($theirs['status']), form: 'partner_of_relative', base: $base, path: $path)];
            }
        }

        if ($candidates === []) {
            foreach ($graph->partnerLinks($x) as $mine) {
                foreach ($graph->partnerLinks($y) as $theirs) {
                    $base = $descent($mine['person'], $theirs['person']);

                    if ($base !== null) {
                        $path = [
                            ['id' => $x, 'via' => null, 'subtype' => null],
                            $step($mine),
                            ...array_slice($base['path'], 1),
                            ['id' => $y, 'via' => 'partner', 'subtype' => $theirs['subtype']],
                        ];
                        $candidates[] = [2, self::relation('in_law', former: self::ended($mine['status']) || self::ended($theirs['status']), form: 'partner_of_relative_of_partner', base: $base, path: $path)];
                    }
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Closest first; a marriage that still stands before one that ended.
        usort($candidates, fn (array $a, array $b): int => [$a[1]['base']['up'] + $a[1]['base']['down'], $a[1]['former'], $a[0]]
            <=> [$b[1]['base']['up'] + $b[1]['base']['down'], $b[1]['former'], $b[0]]);

        return $candidates[0][1];
    }

    /**
     * The shortest chain of parent, child and partner links between two people.
     *
     * @return list<Step>|null
     */
    private function anyChain(FamilyGraph $graph, string $x, string $y): ?array
    {
        $came = [$x => null];
        $queue = [$x];

        for ($i = 0; $i < count($queue); $i++) {
            $person = $queue[$i];

            if ($person === $y) {
                break;
            }

            $next = [];

            foreach ($graph->parentLinks($person) as $link) {
                $next[] = [$link['person'], 'parent', $link['subtype']];
            }

            foreach ($graph->childLinks($person) as $link) {
                $next[] = [$link['person'], 'child', $link['subtype']];
            }

            foreach ($graph->partnerLinks($person) as $link) {
                $next[] = [$link['person'], 'partner', $link['subtype']];
            }

            foreach ($next as [$id, $via, $subtype]) {
                if (! array_key_exists($id, $came)) {
                    $came[$id] = [$person, $via, $subtype];
                    $queue[] = $id;
                }
            }
        }

        if (! array_key_exists($y, $came)) {
            return null;
        }

        $path = [];

        for ($at = $y; $came[$at] !== null; $at = $came[$at][0]) {
            array_unshift($path, ['id' => $at, 'via' => $came[$at][1], 'subtype' => $came[$at][2]]);
        }

        return [['id' => $x, 'via' => null, 'subtype' => null], ...$path];
    }

    /**
     * Every line of ancestors above a person, the person alone included: [person, parent,
     * grandparent, ...]. Each entry records the kind of link that led up to it.
     *
     * @return list<Line>
     */
    private function linesUp(FamilyGraph $graph, string $start, bool $lineageOnly): array
    {
        $lines = [];
        $stack = [[['id' => $start, 'subtype' => null, 'certainty' => null]]];

        while ($stack !== [] && count($lines) < self::MAX_LINES) {
            $line = array_pop($stack);
            $lines[] = $line;

            if (count($line) > self::MAX_GENERATIONS) {
                continue;
            }

            $seen = array_column($line, 'id');

            foreach ($graph->parentLinks($line[count($line) - 1]['id']) as $link) {
                if ($lineageOnly && ! in_array($link['subtype'], self::LINEAGE, true)) {
                    continue;
                }

                // The database forbids loops; this only guards against bad data.
                if (! in_array($link['person'], $seen, true)) {
                    $stack[] = [...$line, ['id' => $link['person'], 'subtype' => $link['subtype'], 'certainty' => $link['certainty']]];
                }
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function lineageParents(FamilyGraph $graph, string $id): array
    {
        return array_values(array_unique(array_column(
            array_filter($graph->parentLinks($id), fn (array $link): bool => in_array($link['subtype'], self::LINEAGE, true)),
            'person',
        )));
    }

    /**
     * Up one line to the shared ancestor, then down the other.
     *
     * @param  Line  $up
     * @param  Line  $down
     * @return list<Step>
     */
    private static function pathThrough(array $up, array $down): array
    {
        $path = [];

        foreach ($up as $index => $step) {
            $path[] = ['id' => $step['id'], 'via' => $index === 0 ? null : 'parent', 'subtype' => $step['subtype']];
        }

        for ($index = count($down) - 2; $index >= 0; $index--) {
            // The link between this person and the one above them is stored on the one above.
            $path[] = ['id' => $down[$index]['id'], 'via' => 'child', 'subtype' => $down[$index + 1]['subtype']];
        }

        return $path;
    }

    /**
     * @param  list<string|null>  $subtypes
     */
    private static function qualifier(array $subtypes): ?string
    {
        foreach (['step', 'foster', 'guardian', 'adoptive'] as $kind) {
            if (in_array($kind, $subtypes, true)) {
                return $kind;
            }
        }

        return null;
    }

    private static function ended(?string $status): bool
    {
        return in_array($status, ['divorced', 'annulled'], true);
    }

    /**
     * @param  array<string, mixed>|null  $base
     * @param  list<Step>  $path
     * @param  list<string>  $apexes
     * @return Relation
     */
    private static function relation(
        string $kind,
        int $up = 0,
        int $down = 0,
        int $degree = 0,
        int $removed = 0,
        bool $half = false,
        ?string $qualifier = null,
        bool $uncertain = false,
        bool $former = false,
        ?string $subtype = null,
        ?string $form = null,
        ?array $base = null,
        array $path = [],
        array $apexes = [],
    ): array {
        return compact('kind', 'up', 'down', 'degree', 'removed', 'half', 'qualifier', 'uncertain', 'former', 'subtype', 'form', 'base', 'path', 'apexes');
    }
}

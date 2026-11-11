<?php

namespace App\Services\Genealogy;

/**
 * The shape of one family tree in memory: who is a parent, child or partner of whom.
 * Pure data with no database access, so the calculators that use it are easy to test.
 *
 * @phpstan-type Edge array{id: string, type: string, a: string, b: string, subtype: string, status: string|null, certainty: string, sequence: int|null, start: string|null}
 * @phpstan-type ParentLink array{id: string, person: string, subtype: string, certainty: string}
 * @phpstan-type PartnerLink array{id: string, person: string, subtype: string, status: string|null, sequence: int|null, start: string|null}
 */
final class FamilyGraph
{
    /** @var array<string, true> */
    private array $members = [];

    /** @var array<string, list<ParentLink>> child id => links to parents */
    private array $parents = [];

    /** @var array<string, list<ParentLink>> parent id => links to children */
    private array $children = [];

    /** @var array<string, list<PartnerLink>> */
    private array $partners = [];

    /**
     * @param  list<string>  $memberIds
     * @param  list<Edge>  $edges
     */
    public function __construct(array $memberIds, array $edges)
    {
        foreach ($memberIds as $id) {
            $this->members[$id] = true;
        }

        foreach ($edges as $edge) {
            // Edges to people who are no longer in the tree are ignored.
            if (! isset($this->members[$edge['a']], $this->members[$edge['b']])) {
                continue;
            }

            if ($edge['type'] === 'parent_child') {
                $this->parents[$edge['b']][] = ['id' => $edge['id'], 'person' => $edge['a'], 'subtype' => $edge['subtype'], 'certainty' => $edge['certainty']];
                $this->children[$edge['a']][] = ['id' => $edge['id'], 'person' => $edge['b'], 'subtype' => $edge['subtype'], 'certainty' => $edge['certainty']];

                continue;
            }

            foreach ([[$edge['a'], $edge['b']], [$edge['b'], $edge['a']]] as [$from, $to]) {
                $this->partners[$from][] = [
                    'id' => $edge['id'],
                    'person' => $to,
                    'subtype' => $edge['subtype'],
                    'status' => $edge['status'],
                    'sequence' => $edge['sequence'],
                    'start' => $edge['start'],
                ];
            }
        }

        foreach ($this->partners as &$links) {
            usort($links, fn (array $x, array $y): int => [$x['sequence'] ?? PHP_INT_MAX, $x['start'] ?? '9999', $x['id']]
                <=> [$y['sequence'] ?? PHP_INT_MAX, $y['start'] ?? '9999', $y['id']]);
        }
    }

    public function has(string $id): bool
    {
        return isset($this->members[$id]);
    }

    /** @return list<string> */
    public function memberIds(): array
    {
        return array_keys($this->members);
    }

    /** @return list<ParentLink> */
    public function parentLinks(string $id): array
    {
        return $this->parents[$id] ?? [];
    }

    /** @return list<ParentLink> */
    public function childLinks(string $id): array
    {
        return $this->children[$id] ?? [];
    }

    /** @return list<PartnerLink> in marriage order (sequence, then start date) */
    public function partnerLinks(string $id): array
    {
        return $this->partners[$id] ?? [];
    }

    /** @return list<string> */
    public function parentIds(string $id): array
    {
        return array_column($this->parentLinks($id), 'person');
    }

    /** @return list<string> */
    public function childIds(string $id): array
    {
        return array_column($this->childLinks($id), 'person');
    }

    /** @return list<string> */
    public function partnerIds(string $id): array
    {
        return array_values(array_unique(array_column($this->partnerLinks($id), 'person')));
    }

    /** @return list<string> */
    public function biologicalParentIds(string $id): array
    {
        return array_column(
            array_filter($this->parentLinks($id), fn (array $link): bool => $link['subtype'] === 'biological'),
            'person',
        );
    }

    /**
     * People who share at least one parent with the given person.
     *
     * @return list<string>
     */
    public function siblingIds(string $id): array
    {
        $siblings = [];

        foreach ($this->parentIds($id) as $parent) {
            foreach ($this->childIds($parent) as $child) {
                if ($child !== $id) {
                    $siblings[$child] = true;
                }
            }
        }

        return array_keys($siblings);
    }

    public function arePartners(string $a, string $b): bool
    {
        return in_array($b, $this->partnerIds($a), true);
    }

    public function isParentOf(string $parent, string $child): bool
    {
        return in_array($parent, $this->parentIds($child), true);
    }

    /**
     * Everyone above the person, with how many generations up they are (nearest first).
     *
     * @return array<string, int> ancestor id => distance
     */
    public function ancestors(string $id): array
    {
        return $this->walk($id, fn (string $person): array => $this->parentIds($person));
    }

    /**
     * Everyone below the person, with how many generations down they are.
     *
     * @return array<string, int> descendant id => distance
     */
    public function descendants(string $id): array
    {
        return $this->walk($id, fn (string $person): array => $this->childIds($person));
    }

    public function isAncestorOf(string $ancestor, string $person): bool
    {
        return isset($this->ancestors($person)[$ancestor]);
    }

    /**
     * @param  callable(string): list<string>  $next
     * @return array<string, int>
     */
    private function walk(string $start, callable $next): array
    {
        $found = [];
        $queue = [[$start, 0]];

        while ($queue !== []) {
            [$person, $distance] = array_shift($queue);

            foreach ($next($person) as $relative) {
                if ($relative === $start || isset($found[$relative])) {
                    continue;
                }

                $found[$relative] = $distance + 1;
                $queue[] = [$relative, $distance + 1];
            }
        }

        return $found;
    }
}

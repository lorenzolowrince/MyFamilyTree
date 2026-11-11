<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

/**
 * Builds the part of the tree shown on screen: the focus person, a few generations of
 * ancestors above and of descendants below, with partners. Never the whole tree, so large
 * families stay fast on phones (docs/architecture/03-relationship-modelling.md).
 *
 * The result only contains ids and relationship facts. What each viewer may see of each
 * person is decided separately by the MemberPresenter.
 *
 * @phpstan-type Union array{partner: string|null, relationship: array{id: string, subtype: string, status: string|null}|null, children: list<array<string, mixed>>}
 * @phpstan-type Slice array{focus: string, descendants: array<string, mixed>, ancestors: array<string, mixed>, people: list<string>}
 */
class TreeSliceBuilder
{
    public const MAX_DEPTH = 6;

    /** @var array<string, array{birth: string, gender: string|null}> */
    private array $facts = [];

    /** @var array<string, true> */
    private array $included = [];

    /**
     * @return Slice
     */
    public function build(FamilyGraph $graph, string $familyId, string $focusId, int $up, int $down): array
    {
        $up = max(0, min(self::MAX_DEPTH, $up));
        $down = max(0, min(self::MAX_DEPTH, $down));

        $this->included = [];
        $this->facts = $this->loadFacts($familyId);

        $seen = [];
        $descendants = $this->descend($graph, $focusId, null, 0, $down, $seen);

        $seen = [];
        $ancestors = $this->ascend($graph, $focusId, null, 0, $up, $seen);

        return [
            'focus' => $focusId,
            'descendants' => $descendants,
            'ancestors' => $ancestors,
            'people' => array_keys($this->included),
        ];
    }

    /**
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function descend(FamilyGraph $graph, string $person, ?string $link, int $depth, int $limit, array &$seen): array
    {
        $this->included[$person] = true;

        // A person reached twice (cousin marriage) is drawn once in full, then as a "see also" card.
        if (isset($seen[$person])) {
            return ['person' => $person, 'link' => $link, 'unions' => [], 'more' => false, 'repeat' => true];
        }

        $seen[$person] = true;

        /** @var array<string, Union> $unions keyed by partner id ('' = no known other parent) */
        $unions = [];

        foreach ($graph->partnerLinks($person) as $partner) {
            // Remarriage to the same person is one couple on screen; the first marriage describes it.
            $unions[$partner['person']] ??= [
                'partner' => $partner['person'],
                'relationship' => ['id' => $partner['id'], 'subtype' => $partner['subtype'], 'status' => $partner['status']],
                'children' => [],
            ];
            $this->included[$partner['person']] = true;
        }

        $childLinks = $graph->childLinks($person);
        $expand = $depth < $limit;

        if ($expand) {
            usort($childLinks, fn (array $x, array $y): int => [$this->birth($x['person']), $x['person']] <=> [$this->birth($y['person']), $y['person']]);

            foreach ($childLinks as $child) {
                $other = $this->coParent($graph, $person, $child['person'], array_keys($unions));
                $key = $other ?? '';

                if (! isset($unions[$key])) {
                    $unions[$key] = ['partner' => $other, 'relationship' => null, 'children' => []];

                    if ($other !== null) {
                        $this->included[$other] = true;
                    }
                }

                $unions[$key]['children'][] = $this->descend($graph, $child['person'], $child['subtype'], $depth + 1, $limit, $seen);
            }
        }

        return [
            'person' => $person,
            'link' => $link,
            'unions' => array_values($unions),
            'more' => ! $expand && $childLinks !== [],
            'repeat' => false,
        ];
    }

    /**
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function ascend(FamilyGraph $graph, string $person, ?string $link, int $depth, int $limit, array &$seen): array
    {
        $this->included[$person] = true;

        if (isset($seen[$person])) {
            return ['person' => $person, 'link' => $link, 'parents' => [], 'more' => false, 'repeat' => true];
        }

        $seen[$person] = true;

        $parentLinks = $graph->parentLinks($person);
        $parents = [];

        if ($depth < $limit) {
            // Biological parents first, father on the left: the conventional pedigree order.
            usort($parentLinks, fn (array $x, array $y): int => [
                $x['subtype'] === 'biological' ? 0 : 1, $this->genderOrder($x['person']), $x['person'],
            ] <=> [
                $y['subtype'] === 'biological' ? 0 : 1, $this->genderOrder($y['person']), $y['person'],
            ]);

            foreach ($parentLinks as $parent) {
                $parents[] = $this->ascend($graph, $parent['person'], $parent['subtype'], $depth + 1, $limit, $seen);
            }
        }

        return [
            'person' => $person,
            'link' => $link,
            'parents' => $parents,
            'more' => $depth >= $limit && $parentLinks !== [],
            'repeat' => false,
        ];
    }

    /**
     * Which couple a child is drawn under: derived, never stored. The child's other parent who
     * is this person's partner, otherwise the other biological parent, otherwise any other parent.
     *
     * @param  list<int|string>  $partnerIds
     */
    private function coParent(FamilyGraph $graph, string $person, string $child, array $partnerIds): ?string
    {
        $others = array_values(array_filter($graph->parentLinks($child), fn (array $link): bool => $link['person'] !== $person));

        if ($others === []) {
            return null;
        }

        foreach ($others as $link) {
            if (in_array($link['person'], $partnerIds, true)) {
                return $link['person'];
            }
        }

        foreach ($others as $link) {
            if ($link['subtype'] === 'biological') {
                return $link['person'];
            }
        }

        return $others[0]['person'];
    }

    private function birth(string $id): string
    {
        return $this->facts[$id]['birth'] ?? '9999-12-31';
    }

    private function genderOrder(string $id): int
    {
        return match ($this->facts[$id]['gender'] ?? null) {
            'male' => 0,
            'female' => 1,
            default => 2,
        };
    }

    /**
     * @return array<string, array{birth: string, gender: string|null}>
     */
    private function loadFacts(string $familyId): array
    {
        $facts = [];

        $rows = DB::table('family_members')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->get(['id', 'birth_date', 'gender']);

        foreach ($rows as $row) {
            $facts[(string) $row->id] = [
                'birth' => $row->birth_date === null ? '9999-12-31' : (string) $row->birth_date,
                'gender' => $row->gender === null ? null : (string) $row->gender,
            ];
        }

        return $facts;
    }
}

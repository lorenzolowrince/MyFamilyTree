<?php

namespace App\Services\Genealogy;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberViewer;
use Illuminate\Database\Eloquent\Collection;

/**
 * The close relatives listed on a person's profile: parents, partners, children and siblings.
 * Siblings are derived from shared parents; nothing here is stored separately.
 */
class RelativesPresenter
{
    public function __construct(
        private readonly FamilyGraphLoader $graphs,
        private readonly MemberPresenter $presenter,
    ) {}

    /**
     * @return array{parents: list<array<string, mixed>>, partners: list<array<string, mixed>>, children: list<array<string, mixed>>, siblings: list<array<string, mixed>>}
     */
    public function for(Family $family, FamilyMember $member, MemberViewer $viewer): array
    {
        $graph = $this->graphs->for($family);

        $parentLinks = $graph->parentLinks($member->id);
        $childLinks = $graph->childLinks($member->id);
        $partnerLinks = $graph->partnerLinks($member->id);
        $siblingIds = $graph->siblingIds($member->id);

        $coParents = [];

        foreach ($childLinks as $link) {
            $coParents[$link['person']] = array_values(array_diff($graph->parentIds($link['person']), [$member->id]));
        }

        $ids = array_unique([
            ...array_column($parentLinks, 'person'),
            ...array_column($childLinks, 'person'),
            ...array_column($partnerLinks, 'person'),
            ...$siblingIds,
            ...array_merge(...array_values($coParents)),
        ]);

        /** @var Collection<string, FamilyMember> $people */
        $people = FamilyMember::query()->inFamily($family)->whereIn('id', $ids)->get()->keyBy('id');

        /** @var Collection<string, FamilyRelationship> $relationships */
        $relationships = FamilyRelationship::query()
            ->where('family_id', $family->id)
            ->whereIn('id', array_column($partnerLinks, 'id'))
            ->with('place')
            ->get()
            ->keyBy('id');

        $summary = fn (string $id): array => isset($people[$id])
            ? $this->presenter->summary($people[$id], $viewer)
            : MemberPresenter::hidden($id);

        $born = fn (string $id): string => (string) ($people->get($id)?->getRawOriginal('birth_date') ?? '9999');

        $byBirth = fn (array $x, array $y): int => [$born($x['person']), $x['person']] <=> [$born($y['person']), $y['person']];

        usort($childLinks, $byBirth);

        $myParents = $graph->parentIds($member->id);
        $myBiological = $graph->biologicalParentIds($member->id);

        $siblings = [];

        foreach ($siblingIds as $sibling) {
            $shared = array_intersect($myParents, $graph->parentIds($sibling));
            $sharedBiological = array_intersect($myBiological, $graph->biologicalParentIds($sibling));

            $siblings[] = [
                'person' => $summary($sibling),
                'kind' => match (true) {
                    count($sharedBiological) >= 2 => 'full',
                    count($sharedBiological) === 1 && (count($myBiological) === 2 || count($graph->biologicalParentIds($sibling)) === 2) => 'half',
                    $sharedBiological === [] && $shared !== [] => 'by_family',
                    default => 'sibling',
                },
                'birth' => $born($sibling),
            ];
        }

        usort($siblings, fn (array $x, array $y): int => [$x['birth'], $x['person']['id']] <=> [$y['birth'], $y['person']['id']]);

        return [
            'parents' => array_map(fn (array $link): array => [
                'relationship' => ['id' => $link['id'], 'type' => 'parent_child', 'subtype' => $link['subtype'], 'certainty' => $link['certainty']],
                'person' => $summary($link['person']),
            ], $parentLinks),

            'partners' => array_map(function (array $link) use ($relationships, $summary): array {
                $relationship = $relationships[$link['id']] ?? null;

                return [
                    'relationship' => [
                        'id' => $link['id'],
                        'type' => 'partnership',
                        'subtype' => $link['subtype'],
                        'status' => $link['status'],
                        'certainty' => $relationship?->certainty->value,
                        'start' => $relationship?->start->toArray(),
                        'end' => $relationship?->end->toArray(),
                        'place' => $relationship?->place?->name,
                        'notes' => $relationship?->notes,
                    ],
                    'person' => $summary($link['person']),
                ];
            }, $partnerLinks),

            'children' => array_map(fn (array $link): array => [
                'relationship' => ['id' => $link['id'], 'type' => 'parent_child', 'subtype' => $link['subtype'], 'certainty' => $link['certainty']],
                'person' => $summary($link['person']),
                'other_parents' => array_map($summary, $coParents[$link['person']] ?? []),
            ], $childLinks),

            'siblings' => array_map(fn (array $sibling): array => ['person' => $sibling['person'], 'kind' => $sibling['kind']], $siblings),
        ];
    }
}

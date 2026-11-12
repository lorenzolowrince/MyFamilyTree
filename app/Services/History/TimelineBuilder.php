<?php

namespace App\Services\History;

use App\Enums\FamilyPermission;
use App\Enums\RelationshipType;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\TimelineEntry;
use App\Services\Media\MediaPresenter;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Services\Privacy\MemberViewer;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Database\Eloquent\Builder;

/**
 * The family timeline: recorded entries merged with births, deaths and marriages taken
 * straight from the people and relationship tables. Everything passes through the viewer's
 * privacy rules, so a living person's birth date or a hidden person never appears here.
 *
 * @phpstan-type Item array{id: string, kind: string, category: string, title: string, description: string|null, date: array<string, mixed>, sort: string, year: int|null, place: string|null, visibility: string|null, people: list<array<string, mixed>>, editable: bool, attachments: list<array<string, mixed>>}
 */
class TimelineBuilder
{
    public const LIMIT = 500;

    public const DERIVED = ['birth', 'death', 'marriage'];

    public function __construct(
        private readonly MemberPrivacy $privacy,
        private readonly MemberPresenter $presenter,
        private readonly MediaPresenter $media,
    ) {}

    /**
     * @param  array{category?: string|null, from?: int|null, to?: int|null, member?: string|null}  $filters
     * @return array{items: list<Item>, truncated: bool}
     */
    public function build(Family $family, MemberViewer $viewer, array $filters = []): array
    {
        $category = $filters['category'] ?? null;
        $memberId = $filters['member'] ?? null;

        $items = [];

        if ($category === null || ! in_array($category, self::DERIVED, true)) {
            $items = [...$items, ...$this->entries($family, $viewer, $category, $memberId)];
        }

        if ($category === null || $category === 'birth' || $category === 'death') {
            $items = [...$items, ...$this->lifeEvents($family, $viewer, $category, $memberId)];
        }

        if ($category === null || $category === 'marriage') {
            $items = [...$items, ...$this->marriages($family, $viewer, $memberId)];
        }

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $items = array_values(array_filter($items, fn (array $item): bool => ($from === null || ($item['year'] !== null && $item['year'] >= $from))
            && ($to === null || ($item['year'] !== null && $item['year'] <= $to))));

        // Dated items in order; undated ones (a description only) at the end.
        usort($items, fn (array $x, array $y): int => [$x['sort'], $x['title']] <=> [$y['sort'], $y['title']]);

        return [
            'items' => array_slice($items, 0, self::LIMIT),
            'truncated' => count($items) > self::LIMIT,
        ];
    }

    /**
     * @return list<Item>
     */
    private function entries(Family $family, MemberViewer $viewer, ?string $category, ?string $memberId): array
    {
        $editable = $viewer->has(FamilyPermission::TimelineCreate);

        $entries = TimelineEntry::query()
            ->where('family_id', $family->id)
            ->whereIn('visibility', $viewer->visibleLevels())
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category))
            ->when($memberId !== null, fn (Builder $query) => $query->whereHas('participants', fn (Builder $query) => $query->where('family_members.id', $memberId)))
            ->with(['participants', 'place'])
            ->get();

        $attachments = $this->media->timelineAttachments($family->id, array_values($entries->modelKeys()), $viewer);

        return array_values($entries
            ->map(fn (TimelineEntry $entry): array => $this->item(
                id: $entry->id,
                kind: 'entry',
                category: $entry->category->value,
                title: $entry->title,
                description: $entry->description,
                date: $entry->event,
                place: $entry->place?->name,
                visibility: $entry->visibility->value,
                // People the viewer may not see are left out rather than shown as "private".
                people: array_values($entry->participants
                    ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
                    ->map(fn (FamilyMember $member): array => $this->presenter->summary($member, $viewer))
                    ->all()),
                editable: $editable,
                attachments: $attachments[$entry->id] ?? [],
            ))
            ->all());
    }

    /**
     * @return list<Item>
     */
    private function lifeEvents(Family $family, MemberViewer $viewer, ?string $category, ?string $memberId): array
    {
        $members = $this->privacy->scopeVisible(FamilyMember::query()->inFamily($family), $viewer)
            ->where('is_placeholder', false)
            ->where(fn (Builder $query) => $query->whereNotNull('birth_date')->orWhereNotNull('death_date'))
            ->when($memberId !== null, fn (Builder $query) => $query->where('family_members.id', $memberId))
            ->with(['birthPlace', 'deathPlace'])
            ->get();

        $items = [];

        foreach ($members as $member) {
            if (! $viewer->canViewSensitive($member)) {
                continue;
            }

            $person = [$this->presenter->summary($member, $viewer)];

            if ($category !== 'death' && $member->birth->isKnown()) {
                $items[] = $this->item("birth-{$member->id}", 'birth', 'birth', __(':name was born', ['name' => $member->full_name]), null, $member->birth, $member->birthPlace?->name, null, $person, false);
            }

            if ($category !== 'birth' && $member->death->isKnown()) {
                $items[] = $this->item("death-{$member->id}", 'death', 'death', __(':name died', ['name' => $member->full_name]), null, $member->death, $member->deathPlace?->name, null, $person, false);
            }
        }

        return $items;
    }

    /**
     * @return list<Item>
     */
    private function marriages(Family $family, MemberViewer $viewer, ?string $memberId): array
    {
        $relationships = FamilyRelationship::query()
            ->where('family_id', $family->id)
            ->where('type', RelationshipType::Partnership->value)
            ->whereNotNull('start_date')
            ->when($memberId !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('person_a_id', $memberId)->orWhere('person_b_id', $memberId)))
            ->with(['personA', 'personB', 'place'])
            ->get();

        $items = [];

        foreach ($relationships as $relationship) {
            $a = $relationship->personA;
            $b = $relationship->personB;

            // Both partners must be visible, and their dates open to this viewer.
            if ($a === null || $b === null || ! $viewer->canViewSensitive($a) || ! $viewer->canViewSensitive($b)) {
                continue;
            }

            $items[] = $this->item(
                "marriage-{$relationship->id}",
                'marriage',
                'marriage',
                __(':a and :b: :kind', ['a' => $a->full_name, 'b' => $b->full_name, 'kind' => mb_strtolower($relationship->subtypeLabel())]),
                null,
                $relationship->start,
                $relationship->place?->name,
                null,
                [$this->presenter->summary($a, $viewer), $this->presenter->summary($b, $viewer)],
                false,
            );
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @param  list<array<string, mixed>>  $attachments
     * @return Item
     */
    private function item(string $id, string $kind, string $category, string $title, ?string $description, FuzzyDate $date, ?string $place, ?string $visibility, array $people, bool $editable, array $attachments = []): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'date' => $date->toArray(),
            'sort' => $date->date?->toDateString() ?? '9999-12-31',
            'year' => $date->yearValue(),
            'place' => $place,
            'visibility' => $visibility,
            'people' => $people,
            'editable' => $editable,
            'attachments' => $attachments,
        ];
    }
}

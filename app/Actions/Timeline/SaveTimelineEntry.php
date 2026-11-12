<?php

namespace App\Actions\Timeline;

use App\Enums\TimelineCategory;
use App\Enums\Visibility;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Place;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\Privacy\MemberViewer;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Support\Facades\DB;

/** Creates or updates a timeline entry and its participants. */
class SaveTimelineEntry
{
    /**
     * @param  array<string, mixed>  $data  validated fields (TimelineEntryRequest)
     * @param  MemberViewer  $viewer  whose privacy rules limit visibility and participants
     */
    public function handle(TimelineEntry $entry, Family $family, User $actor, array $data, MemberViewer $viewer): TimelineEntry
    {
        return DB::transaction(function () use ($entry, $family, $actor, $data, $viewer): TimelineEntry {
            $entry->family_id = $family->id;
            $entry->title = trim((string) $data['title']);
            $entry->category = TimelineCategory::from((string) $data['category']);
            $entry->description = isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null;
            $entry->event = FuzzyDate::fromInput(is_array($data['event'] ?? null) ? $data['event'] : null);
            $entry->place_id = Place::resolve($family, isset($data['place']) ? (string) $data['place'] : null)?->id;
            $entry->updated_by = $actor->id;

            if (! $entry->exists) {
                $entry->created_by = $actor->id;
            }

            // Nobody can hide an entry at a level they could not then see themselves.
            if (isset($data['visibility']) && in_array($data['visibility'], $viewer->visibleLevels(), true)) {
                $entry->visibility = Visibility::from((string) $data['visibility']);
            }

            $entry->save();

            /** @var list<string> $ids */
            $ids = $data['participant_ids'] ?? [];

            // People the editor may not see cannot be attached, and ones already attached stay.
            $visible = FamilyMember::query()->inFamily($family)->whereIn('id', $ids)->get()
                ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
                ->modelKeys();

            $hidden = $entry->participants()->get()
                ->reject(fn (FamilyMember $member): bool => $viewer->canView($member))
                ->modelKeys();

            $sync = [];

            foreach ([...$visible, ...$hidden] as $id) {
                $sync[$id] = ['family_id' => $family->id];
            }

            $entry->participants()->sync($sync);

            return $entry;
        });
    }
}

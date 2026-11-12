<?php

namespace App\Actions\Media;

use App\Enums\DocumentCategory;
use App\Enums\Visibility;
use App\Models\FamilyDocument;
use App\Models\FamilyMember;
use App\Models\FamilyPhoto;
use App\Services\Privacy\MemberViewer;
use App\Support\FuzzyDate\FuzzyDate;

/** Applies the editable details of a photo or document, within the editor's privacy limits. */
class SaveMediaDetails
{
    /**
     * Set the fields present in `$data`. Does not save.
     *
     * @param  array<string, mixed>  $data  validated fields (MediaDetailsRequest)
     */
    public function fill(FamilyPhoto|FamilyDocument $file, array $data, MemberViewer $viewer): void
    {
        $text = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        if (array_key_exists('description', $data)) {
            $file->description = $text('description');
        }

        // Nobody can hide a file at a level they could not then see themselves.
        if (isset($data['visibility']) && in_array($data['visibility'], $viewer->visibleLevels(), true)) {
            $file->visibility = Visibility::from((string) $data['visibility']);
        }

        if ($file instanceof FamilyPhoto) {
            if (array_key_exists('caption', $data)) {
                $file->caption = $text('caption');
            }

            if (array_key_exists('taken', $data)) {
                $file->taken = FuzzyDate::fromInput(is_array($data['taken']) ? $data['taken'] : null);
            }

            if (array_key_exists('album_id', $data)) {
                $file->album_id = $data['album_id'] === null ? null : (string) $data['album_id'];
            }

            return;
        }

        if (array_key_exists('title', $data)) {
            $file->title = $text('title');
        }

        if (array_key_exists('issued', $data)) {
            $file->issued = FuzzyDate::fromInput(is_array($data['issued']) ? $data['issued'] : null);
        }

        if (isset($data['category'])) {
            $file->category = DocumentCategory::from((string) $data['category']);
        }

        if (array_key_exists('downloadable', $data)) {
            $file->downloadable = (bool) $data['downloadable'];
        }
    }

    /**
     * Replace the people linked to a saved file. People the editor may not see cannot be
     * added, and ones already linked stay.
     *
     * @param  list<string>  $memberIds
     * @return list<string> the people newly linked
     */
    public function syncPeople(FamilyPhoto|FamilyDocument $file, array $memberIds, MemberViewer $viewer): array
    {
        $current = $file->people()->get();

        $visible = FamilyMember::query()->where('family_id', $file->family_id)->whereIn('id', $memberIds)->get()
            ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
            ->modelKeys();

        $hidden = $current->reject(fn (FamilyMember $member): bool => $viewer->canView($member))->modelKeys();

        $sync = [];

        foreach ([...$visible, ...$hidden] as $id) {
            $sync[$id] = ['family_id' => $file->family_id];
        }

        $file->people()->sync($sync);

        // Someone no longer in the photo cannot keep it as their profile picture.
        if ($file instanceof FamilyPhoto) {
            FamilyMember::query()
                ->where('family_id', $file->family_id)
                ->where('profile_photo_id', $file->id)
                ->whereNotIn('id', array_keys($sync))
                ->update(['profile_photo_id' => null]);
        }

        return array_values(array_diff(array_keys($sync), $current->modelKeys()));
    }
}

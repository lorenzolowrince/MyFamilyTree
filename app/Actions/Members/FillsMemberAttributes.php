<?php

namespace App\Actions\Members;

use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Place;
use App\Support\FuzzyDate\FuzzyDate;

/**
 * Copies validated input onto a person. Only keys that are present are touched, so the quick
 * "add relative" form (name, gender, birth year) and the full editor share one path.
 */
trait FillsMemberAttributes
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function fillMember(FamilyMember $member, Family $family, array $data): void
    {
        foreach (['full_name', 'given_names', 'surname', 'maiden_name', 'nickname', 'occupation', 'education', 'nationality', 'biography', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                $member->{$field} = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('gender', $data)) {
            $member->gender = Gender::tryFrom((string) $data['gender']);
        }

        if (array_key_exists('visibility', $data)) {
            $member->visibility = Visibility::from((string) $data['visibility']);
        }

        if (array_key_exists('living_status', $data)) {
            $member->living_status = LivingStatus::from((string) $data['living_status']);
        }

        if (array_key_exists('birth', $data)) {
            $member->birth = FuzzyDate::fromInput(is_array($data['birth']) ? $data['birth'] : null);
        }

        if (array_key_exists('death', $data)) {
            $member->death = FuzzyDate::fromInput(is_array($data['death']) ? $data['death'] : null);
        }

        // A death date means the person has died; someone not deceased has no death date.
        if ($member->death->isKnown()) {
            $member->living_status = LivingStatus::Deceased;
        } elseif ($member->living_status !== LivingStatus::Deceased && ! $member->death->isEmpty()) {
            $member->death = FuzzyDate::unknown();
        }

        foreach (['birth_place' => 'birth_place_id', 'death_place' => 'death_place_id', 'hometown' => 'hometown_place_id'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $member->{$column} = Place::resolve($family, is_string($data[$field]) ? $data[$field] : null)?->id;
            }
        }
    }
}

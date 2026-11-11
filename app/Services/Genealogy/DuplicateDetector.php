<?php

namespace App\Services\Genealogy;

use App\Models\Family;
use App\Models\FamilyMember;
use Illuminate\Support\Collection;

/**
 * "Is this one of these people?" Finds people already in the tree who look like the person
 * about to be added: similar name (trigram match, accents ignored), boosted when the birth
 * year is close. Run before a new person is created so nobody is entered twice.
 */
class DuplicateDetector
{
    private const MINIMUM_SIMILARITY = 0.35;

    /**
     * @return Collection<int, FamilyMember> most likely first
     */
    public function find(Family $family, string $name, ?int $birthYear = null, int $limit = 5): Collection
    {
        $name = trim($name);

        if (mb_strlen($name) < 2) {
            /** @var Collection<int, FamilyMember> */
            return new Collection;
        }

        $similarity = 'similarity(immutable_unaccent(lower(full_name)), immutable_unaccent(lower(?)))';

        return FamilyMember::query()
            ->inFamily($family)
            ->where('is_placeholder', false)
            ->whereRaw("{$similarity} >= ?", [$name, self::MINIMUM_SIMILARITY])
            ->selectRaw("family_members.*, {$similarity} AS name_similarity", [$name])
            ->orderByRaw(
                "{$similarity} + CASE WHEN ?::int IS NOT NULL AND birth_date IS NOT NULL AND abs(EXTRACT(YEAR FROM birth_date)::int - ?::int) <= 2 THEN 0.3 ELSE 0 END DESC",
                [$name, $birthYear, $birthYear],
            )
            ->orderBy('full_name')
            ->limit($limit)
            ->get();
    }
}

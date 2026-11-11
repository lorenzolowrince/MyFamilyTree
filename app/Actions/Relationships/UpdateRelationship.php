<?php

namespace App\Actions\Relationships;

use App\Enums\Certainty;
use App\Enums\ParentSubtype;
use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;
use App\Exceptions\InvalidRelationshipException;
use App\Models\FamilyRelationship;
use App\Models\Place;
use App\Models\User;
use App\Services\Genealogy\GraphChange;
use App\Support\FuzzyDate\FuzzyDate;

/** Changes the details of an existing link (kind of parent, marriage status and dates...). */
class UpdateRelationship
{
    public function __construct(private readonly GraphChange $graphChange) {}

    /**
     * @param  array<string, mixed>  $data  validated fields
     *
     * @throws InvalidRelationshipException
     */
    public function handle(FamilyRelationship $relationship, User $actor, array $data): FamilyRelationship
    {
        $family = $relationship->family()->firstOrFail();

        return $this->graphChange->run($family, function () use ($relationship, $actor, $data, $family): FamilyRelationship {
            if (array_key_exists('subtype', $data)) {
                $relationship->subtype = $relationship->isParentChild()
                    ? ParentSubtype::from((string) $data['subtype'])->value
                    : PartnershipSubtype::from((string) $data['subtype'])->value;
            }

            if (array_key_exists('certainty', $data)) {
                $relationship->certainty = Certainty::from((string) $data['certainty']);
            }

            if ($relationship->isPartnership()) {
                if (array_key_exists('status', $data)) {
                    $relationship->status = PartnershipStatus::from((string) $data['status']);
                }

                if (array_key_exists('start', $data)) {
                    $relationship->start = FuzzyDate::fromInput(is_array($data['start']) ? $data['start'] : null);
                }

                if (array_key_exists('end', $data)) {
                    $relationship->end = FuzzyDate::fromInput(is_array($data['end']) ? $data['end'] : null);
                }

                if (array_key_exists('place', $data)) {
                    $relationship->place_id = Place::resolve($family, is_string($data['place']) ? $data['place'] : null)?->id;
                }
            }

            if (array_key_exists('notes', $data)) {
                $relationship->notes = is_string($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null;
            }

            $relationship->updated_by = $actor->id;
            $relationship->save();

            return $relationship;
        });
    }
}

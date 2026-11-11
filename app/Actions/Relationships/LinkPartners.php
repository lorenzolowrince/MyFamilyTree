<?php

namespace App\Actions\Relationships;

use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;
use App\Enums\RelationshipType;
use App\Exceptions\InvalidRelationshipException;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\Place;
use App\Models\User;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\GraphChange;
use App\Services\Genealogy\RelationshipRules;
use App\Support\FuzzyDate\FuzzyDate;

/**
 * Records a marriage or partnership. A person may have several, at the same time or one after
 * another; nothing here assumes monogamy.
 */
class LinkPartners
{
    public function __construct(
        private readonly GraphChange $graphChange,
        private readonly FamilyGraphLoader $graphs,
        private readonly RelationshipRules $rules,
    ) {}

    /**
     * @param  array{subtype?: string|null, status?: string|null, start?: array<string, mixed>|null, end?: array<string, mixed>|null, place?: string|null, notes?: string|null}  $details
     *
     * @throws InvalidRelationshipException
     */
    public function handle(Family $family, ?User $actor, FamilyMember $one, FamilyMember $other, array $details = []): FamilyRelationship
    {
        return $this->graphChange->run($family, function () use ($family, $actor, $one, $other, $details): FamilyRelationship {
            $graph = $this->graphs->for($family);
            $this->rules->assertCanBePartners($graph, $one, $other);

            // Canonical order: the lower id is always person A.
            [$a, $b] = strcmp($one->id, $other->id) < 0 ? [$one, $other] : [$other, $one];

            $relationship = new FamilyRelationship;
            $relationship->family_id = $family->id;
            $relationship->type = RelationshipType::Partnership;
            $relationship->person_a_id = $a->id;
            $relationship->person_b_id = $b->id;
            $relationship->subtype = (PartnershipSubtype::tryFrom((string) ($details['subtype'] ?? '')) ?? PartnershipSubtype::Marriage)->value;
            $relationship->status = PartnershipStatus::tryFrom((string) ($details['status'] ?? '')) ?? PartnershipStatus::Current;
            $relationship->start = FuzzyDate::fromInput($details['start'] ?? null);
            $relationship->end = FuzzyDate::fromInput($details['end'] ?? null);
            $relationship->place_id = Place::resolve($family, $details['place'] ?? null)?->id;
            $relationship->notes = $details['notes'] ?? null;
            // Order of this person's marriages when dates are unknown.
            $relationship->sequence = count($graph->partnerLinks($one->id)) + 1;
            $relationship->created_by = $actor?->id;
            $relationship->updated_by = $actor?->id;
            $relationship->save();

            return $relationship;
        });
    }
}

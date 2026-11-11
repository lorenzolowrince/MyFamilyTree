<?php

namespace App\Actions\Relationships;

use App\Enums\Certainty;
use App\Enums\ParentSubtype;
use App\Enums\RelationshipType;
use App\Exceptions\InvalidRelationshipException;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\User;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\GraphChange;
use App\Services\Genealogy\RelationshipRules;

/** Records that one person is a parent of another. */
class LinkParentAndChild
{
    public function __construct(
        private readonly GraphChange $graphChange,
        private readonly FamilyGraphLoader $graphs,
        private readonly RelationshipRules $rules,
    ) {}

    /**
     * @throws InvalidRelationshipException
     */
    public function handle(
        Family $family,
        ?User $actor,
        FamilyMember $parent,
        FamilyMember $child,
        ParentSubtype $subtype = ParentSubtype::Biological,
        Certainty $certainty = Certainty::Confirmed,
    ): FamilyRelationship {
        return $this->graphChange->run($family, function () use ($family, $actor, $parent, $child, $subtype, $certainty): FamilyRelationship {
            $this->rules->assertCanBeParent($this->graphs->for($family), $parent, $child, $subtype);

            $relationship = new FamilyRelationship;
            $relationship->family_id = $family->id;
            $relationship->type = RelationshipType::ParentChild;
            $relationship->person_a_id = $parent->id;
            $relationship->person_b_id = $child->id;
            $relationship->subtype = $subtype->value;
            $relationship->certainty = $certainty;
            $relationship->created_by = $actor?->id;
            $relationship->updated_by = $actor?->id;
            $relationship->save();

            return $relationship;
        });
    }
}

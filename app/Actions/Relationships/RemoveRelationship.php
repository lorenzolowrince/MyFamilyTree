<?php

namespace App\Actions\Relationships;

use App\Models\FamilyRelationship;
use App\Models\User;
use App\Services\Genealogy\GraphChange;

/** Removes a link between two people. The people themselves stay in the tree. */
class RemoveRelationship
{
    public function __construct(private readonly GraphChange $graphChange) {}

    public function handle(FamilyRelationship $relationship, User $actor): void
    {
        $family = $relationship->family()->firstOrFail();

        $this->graphChange->run($family, function () use ($relationship, $actor): void {
            $relationship->updated_by = $actor->id;
            $relationship->save();
            $relationship->delete();
        });
    }
}

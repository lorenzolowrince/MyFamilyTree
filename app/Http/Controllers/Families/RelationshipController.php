<?php

namespace App\Http\Controllers\Families;

use App\Actions\Relationships\RemoveRelationship;
use App\Actions\Relationships\UpdateRelationship;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\UpdateRelationshipRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Editing and removing the links between people. */
class RelationshipController extends Controller
{
    public function update(UpdateRelationshipRequest $request, Family $family, FamilyRelationship $relationship, UpdateRelationship $update): RedirectResponse
    {
        $this->authorizeFor($family, $relationship);

        /** @var User $user */
        $user = $request->user();

        $update->handle($relationship, $user, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Relationship updated.')]);

        return back();
    }

    public function destroy(Request $request, Family $family, FamilyRelationship $relationship, RemoveRelationship $remove): RedirectResponse
    {
        $this->authorizeFor($family, $relationship);

        /** @var User $user */
        $user = $request->user();

        $remove->handle($relationship, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The link was removed. Both people are still in the tree.')]);

        return back();
    }

    /** The role must allow it, and both people must be visible to this person. */
    private function authorizeFor(Family $family, FamilyRelationship $relationship): void
    {
        $this->authorize('manageRelationshipsDirectly', [FamilyMember::class, $family]);

        foreach ([$relationship->personA, $relationship->personB] as $person) {
            abort_if($person === null, 404);
            $this->authorize('view', $person);
        }
    }
}

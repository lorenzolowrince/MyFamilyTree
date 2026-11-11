<?php

namespace App\Http\Controllers\Families;

use App\Actions\Relationships\AddRelative;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\StoreRelativeRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/** "Add parent / child / partner / sibling" from a person's card or profile. */
class RelativeController extends Controller
{
    public function store(StoreRelativeRequest $request, Family $family, FamilyMember $member, AddRelative $add): RedirectResponse
    {
        $this->authorize('view', $member);
        $this->authorize('manageRelationships', [FamilyMember::class, $family]);

        /** @var User $user */
        $user = $request->user();

        $existingId = $request->validated('existing_member_id');

        if ($existingId !== null) {
            $relative = FamilyMember::query()->inFamily($family)->whereKey((string) $existingId)->firstOrFail();
            // Linking to a record you may not see would reveal it.
            $this->authorize('view', $relative);
        } else {
            $this->authorize('create', [FamilyMember::class, $family]);

            /** @var array<string, mixed> $relative */
            $relative = (array) $request->validated('member');
        }

        $person = $add->handle($family, $user, $member, $request->kind(), $relative, $request->options());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is now linked to :other.', ['name' => $person->full_name, 'other' => $member->full_name])]);

        return back();
    }
}

<?php

namespace App\Http\Controllers\Families;

use App\Enums\NameType;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\MemberName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Alternate and previous names of a person. Whoever may edit the person may edit these. */
class MemberNameController extends Controller
{
    public function store(Request $request, Family $family, FamilyMember $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', 'not_regex:/[\p{Cc}<>]/u'],
            'type' => ['required', Rule::enum(NameType::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if($member->names()->count() >= 20, 422, __('A person can have at most 20 other names.'));

        $name = new MemberName;
        $name->family_id = $family->id;
        $name->member_id = $member->id;
        $name->name = trim((string) $data['name']);
        $name->type = NameType::from((string) $data['type']);
        $name->notes = isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null;
        $name->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Name added.')]);

        return back();
    }

    public function destroy(Family $family, FamilyMember $member, MemberName $name): RedirectResponse
    {
        $this->authorize('update', $member);

        $name->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Name removed.')]);

        return back();
    }
}

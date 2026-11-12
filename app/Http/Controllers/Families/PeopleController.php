<?php

namespace App\Http\Controllers\Families;

use App\Actions\Collaboration\InviteToFamily;
use App\Actions\Collaboration\ManageMemberships;
use App\Enums\FamilyRole;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMember;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Authorization\FamilyAccess;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** People with access to a family: roles, invitations, removing, leaving, transferring ownership. */
class PeopleController extends Controller
{
    public function __construct(private readonly FamilyAccess $access) {}

    public function index(Request $request, Family $family): Response
    {
        $this->authorize('view', $family);

        /** @var User $user */
        $user = $request->user();
        $role = $this->access->role($user, $family);
        $canInvite = $user->can('invite', $family);
        $canManage = $user->can('update', $family);

        $linked = FamilyMember::query()->inFamily($family)
            ->whereIn('id', FamilyMembership::query()->where('family_id', $family->id)->whereNotNull('linked_member_id')->select('linked_member_id'))
            ->pluck('full_name', 'id');

        $people = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->with('user')
            ->orderBy('role_id')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (FamilyMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user?->name,
                // Email addresses are for those who manage access, not for every relative.
                'email' => $canInvite ? $membership->user?->email : null,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'status' => $membership->status->value,
                'joined_at' => $membership->joined_at?->toIso8601String(),
                'linked_member' => $membership->linked_member_id === null ? null : [
                    'id' => $membership->linked_member_id,
                    'name' => $linked[$membership->linked_member_id] ?? null,
                ],
                'is_me' => $membership->user_id === $user->id,
                'can' => [
                    'manage' => $role !== null && $membership->user_id !== $user->id && $role->outranks($membership->role)
                        && $user->can('assignRole', [$family, FamilyRole::Viewer]),
                    'link' => $canManage,
                ],
            ]);

        return Inertia::render('people/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'people' => $people,
            'invitations' => ! $canInvite ? [] : FamilyInvitation::query()
                ->where('family_id', $family->id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (FamilyInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role_label' => $invitation->role->label(),
                    'state' => $invitation->state(),
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'created_at' => $invitation->created_at?->toIso8601String(),
                ]),
            'assignableRoles' => array_values(array_map(
                fn (FamilyRole $case): array => ['value' => $case->value, 'label' => $case->label()],
                array_filter(FamilyRole::cases(), fn (FamilyRole $case): bool => $case !== FamilyRole::Owner && $this->access->canAssignRole($user, $family, $case)),
            )),
            'myRole' => $role?->value,
            'can' => [
                'invite' => $canInvite,
                'transfer' => $user->can('transferOwnership', $family),
                'leave' => $role !== null && $role !== FamilyRole::Owner,
            ],
        ]);
    }

    public function invite(Request $request, Family $family, InviteToFamily $invite): RedirectResponse
    {
        $this->authorize('invite', $family);

        // People type addresses with capitals; store one spelling instead of refusing.
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(FamilyRole::class)->except([FamilyRole::Owner])],
            'message' => ['nullable', 'string', 'max:1000'],
            'member_id' => ['nullable', 'uuid', Rule::exists('family_members', 'id')->where('family_id', $family->id)->whereNull('deleted_at')],
        ]);

        $role = FamilyRole::from((string) $data['role']);
        $this->authorize('assignRole', [$family, $role]);

        /** @var User $user */
        $user = $request->user();

        try {
            $invite->handle($family, $user, (string) $data['email'], $role, $data['message'] ?? null, $data['member_id'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $data['email']])]);

        return back();
    }

    public function revokeInvitation(Request $request, Family $family, FamilyInvitation $invitation, HistoryRecorder $history): RedirectResponse
    {
        $this->authorize('invite', $family);

        if ($invitation->isOpen()) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $history->audit('invitation.revoked', $family->id, 'invitation', $invitation->id, null, ['email' => $invitation->email]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled. Its link no longer works.')]);

        return back();
    }

    public function updateRole(Request $request, Family $family, FamilyMembership $membership, ManageMemberships $manage): RedirectResponse
    {
        $this->authorize('view', $family);

        $data = $request->validate(['role' => ['required', Rule::enum(FamilyRole::class)->except([FamilyRole::Owner])]]);

        /** @var User $user */
        $user = $request->user();

        $manage->changeRole($family, $user, $membership, FamilyRole::from((string) $data['role']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return back();
    }

    public function destroy(Request $request, Family $family, FamilyMembership $membership, ManageMemberships $manage): RedirectResponse
    {
        $this->authorize('view', $family);

        /** @var User $user */
        $user = $request->user();
        $leaving = $membership->user_id === $user->id;

        $manage->remove($family, $user, $membership);

        Inertia::flash('toast', ['type' => 'success', 'message' => $leaving ? __('You have left the family.') : __('Removed from the family.')]);

        return $leaving ? to_route('families.index') : back();
    }

    public function link(Request $request, Family $family, FamilyMembership $membership, ManageMemberships $manage): RedirectResponse
    {
        $this->authorize('update', $family);

        $data = $request->validate([
            'member_id' => ['nullable', 'uuid', Rule::exists('family_members', 'id')->where('family_id', $family->id)->whereNull('deleted_at')],
        ]);

        /** @var User $user */
        $user = $request->user();

        $member = isset($data['member_id']) ? FamilyMember::query()->inFamily($family)->whereKey((string) $data['member_id'])->firstOrFail() : null;

        if ($member !== null) {
            $this->authorize('view', $member);
        }

        $manage->link($family, $user, $membership, $member);

        Inertia::flash('toast', ['type' => 'success', 'message' => $member === null ? __('Link removed.') : __('Linked to :name.', ['name' => $member->full_name])]);

        return back();
    }

    public function transfer(Request $request, Family $family, FamilyMembership $membership, ManageMemberships $manage): RedirectResponse
    {
        $this->authorize('transferOwnership', $family);

        // Re-authentication: handing over a family is not undoable by the old owner.
        $request->validate(['password' => ['required', 'current_password']]);

        /** @var User $user */
        $user = $request->user();

        $manage->transferOwnership($family, $user, $membership);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ownership transferred. You are now an administrator.')]);

        return back();
    }
}

<?php

namespace App\Actions\Collaboration;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Who belongs to a family and in which role. Every method checks its own rule
 * ("only roles below your own", "exactly one owner") and writes to the audit log.
 */
class ManageMemberships
{
    public function __construct(
        private readonly FamilyAccess $access,
        private readonly HistoryRecorder $history,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function changeRole(Family $family, User $actor, FamilyMembership $target, FamilyRole $role): void
    {
        $this->assertOutranks($family, $actor, $target, FamilyPermission::RolesAssign);

        if ($role === FamilyRole::Owner || ! $this->access->canAssignRole($actor, $family, $role)) {
            throw new AuthorizationException(__('You can only assign roles below your own.'));
        }

        $old = $target->role;
        $target->role = $role;
        $target->save();
        $this->access->forgetMemberships();

        $this->history->audit('membership.role_changed', $family->id, 'membership', $target->id, ['role' => $old->value], ['role' => $role->value, 'user_id' => $target->user_id], $actor);
        $this->history->activity($family->id, 'membership.role_changed', __('changed :name\'s role to :role', ['name' => $target->user()->value('name'), 'role' => $role->label()]), 'user', $target->user_id, 'family', $actor);
    }

    /**
     * Remove someone from the family, or leave it yourself. The Owner cannot leave:
     * ownership must be transferred first.
     *
     * @throws AuthorizationException
     */
    public function remove(Family $family, User $actor, FamilyMembership $target): void
    {
        if ($target->role === FamilyRole::Owner) {
            throw new AuthorizationException(__('The owner cannot be removed. Transfer ownership first.'));
        }

        if ($target->user_id !== $actor->id) {
            $this->assertOutranks($family, $actor, $target, FamilyPermission::MembersRemove);
        }

        $name = $target->user()->value('name');
        $target->delete();
        $this->access->forgetMemberships();

        $this->history->audit('membership.removed', $family->id, 'membership', $target->id, ['user_id' => $target->user_id, 'role' => $target->role->value], null, $actor);
        $this->history->activity($family->id, 'membership.removed', $target->user_id === $actor->id
            ? __('left the family')
            : __('removed :name from the family', ['name' => $name]), 'user', $target->user_id, 'family', $actor);
    }

    /**
     * Hand the family to another member. The previous owner becomes an Administrator.
     *
     * @throws AuthorizationException
     */
    public function transferOwnership(Family $family, User $owner, FamilyMembership $target): void
    {
        $current = $this->access->membership($owner, $family);

        if ($current === null || $current->role !== FamilyRole::Owner || $target->user_id === $owner->id || ! $target->isActive()) {
            throw new AuthorizationException(__('Only the owner can transfer the family, and only to another active member.'));
        }

        DB::transaction(function () use ($family, $current, $target): void {
            // The old owner steps down first: the database allows one owner at a time.
            $current->role = FamilyRole::Admin;
            $current->save();

            $target->role = FamilyRole::Owner;
            $target->save();

            $family->forceFill(['owner_id' => $target->user_id])->save();
        });

        $this->access->forgetMemberships();

        $this->history->audit('family.ownership_transferred', $family->id, 'family', $family->id, ['owner_id' => $owner->id], ['owner_id' => $target->user_id], $owner);
        $this->history->activity($family->id, 'family.ownership_transferred', __('transferred ownership of the family to :name', ['name' => $target->user()->value('name')]), 'family', $family->id, 'family', $owner);
    }

    /** "This person in the tree is this account." One account per person. */
    public function link(Family $family, User $actor, FamilyMembership $target, ?FamilyMember $member): void
    {
        if ($member !== null) {
            FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('linked_member_id', $member->id)
                ->whereKeyNot($target->id)
                ->update(['linked_member_id' => null]);
        }

        $old = $target->linked_member_id;
        $target->forceFill(['linked_member_id' => $member?->id])->save();
        $this->access->forgetMemberships();

        $this->history->audit('membership.linked', $family->id, 'membership', $target->id, ['linked_member_id' => $old], ['linked_member_id' => $member?->id], $actor);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertOutranks(Family $family, User $actor, FamilyMembership $target, FamilyPermission $permission): void
    {
        $role = $this->access->role($actor, $family);

        if ($role === null
            || $target->user_id === $actor->id
            || ! $this->access->roleHas($role, $permission)
            || ! $role->outranks($target->role)) {
            throw new AuthorizationException(__('You can only manage people whose role is below your own.'));
        }
    }
}

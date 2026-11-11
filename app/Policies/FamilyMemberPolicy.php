<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use App\Services\Privacy\MemberPrivacy;
use Illuminate\Auth\Access\Response;

/**
 * Authorisation for the people in a tree and the links between them.
 *
 * Order of checks: active member of the family (otherwise "not found") → record visibility
 * (a record the viewer may not see is also "not found") → the role's permission.
 *
 * Contributors' additions and edits become change requests in Phase 6; until then they can
 * edit only their own linked profile.
 */
class FamilyMemberPolicy
{
    public function __construct(
        private readonly FamilyAccess $access,
        private readonly MemberPrivacy $privacy,
    ) {}

    public function viewAny(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::TreeView);
    }

    public function view(User $user, FamilyMember $member): Response
    {
        $family = $this->family($member);

        return $this->privacy->viewer($user, $family)->canView($member)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::MembersCreate, $this->proposalNotice($user, $family));
    }

    public function update(User $user, FamilyMember $member): Response
    {
        $family = $this->family($member);
        $viewer = $this->privacy->viewer($user, $family);

        if (! $viewer->canView($member)) {
            return Response::denyAsNotFound();
        }

        if ($viewer->has(FamilyPermission::MembersUpdate)) {
            return Response::allow();
        }

        // "Own linked profile: allowed" for Contributors.
        if ($viewer->isSelf($member) && $viewer->role === FamilyRole::Contributor) {
            return Response::allow();
        }

        return Response::deny($this->proposalNotice($user, $family));
    }

    public function delete(User $user, FamilyMember $member): Response
    {
        $family = $this->family($member);

        if (! $this->privacy->viewer($user, $family)->canView($member)) {
            return Response::denyAsNotFound();
        }

        return $this->check($user, $family, FamilyPermission::MembersDelete);
    }

    /** Add, change or remove links between people. */
    public function manageRelationships(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::RelationshipsManage, $this->proposalNotice($user, $family));
    }

    private function check(User $user, Family $family, FamilyPermission $permission, ?string $message = null): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, $permission)
            ? Response::allow()
            : Response::deny($message);
    }

    private function proposalNotice(User $user, Family $family): ?string
    {
        return $this->access->can($user, $family, FamilyPermission::ChangesPropose)
            ? (string) __('Your role submits changes for approval. Submitting proposals is not available yet.')
            : null;
    }

    private function family(FamilyMember $member): Family
    {
        return $member->relationLoaded('family') && $member->family !== null
            ? $member->family
            : $member->family()->withTrashed()->firstOrFail();
    }
}

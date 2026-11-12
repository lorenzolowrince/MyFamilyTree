<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use App\Services\Collaboration\ChangeMode;
use App\Services\Privacy\MemberPrivacy;
use Illuminate\Auth\Access\Response;

/**
 * Authorisation for the people in a tree and the links between them.
 *
 * Order of checks: active member of the family (otherwise "not found") → record visibility
 * (a record the viewer may not see is also "not found") → what the role allows.
 *
 * `create`, `update`, `delete` and `manageRelationships` answer "may this person submit this
 * change at all?". Whether it is applied at once or goes to the approval queue is decided by
 * ChangeMode. The `...Directly` abilities are for changes that have no approval path.
 */
class FamilyMemberPolicy
{
    public function __construct(
        private readonly FamilyAccess $access,
        private readonly MemberPrivacy $privacy,
        private readonly ChangeMode $mode,
    ) {}

    public function viewAny(User $user, Family $family): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, FamilyPermission::TreeView) ? Response::allow() : Response::deny();
    }

    public function view(User $user, FamilyMember $member): Response
    {
        return $this->privacy->viewer($user, $this->family($member))->canView($member)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Family $family): Response
    {
        return $this->unlessDenied($user, $family, $this->mode->forEdit($user, $family));
    }

    public function update(User $user, FamilyMember $member): Response
    {
        $family = $this->family($member);

        if (! $this->privacy->viewer($user, $family)->canView($member)) {
            return Response::denyAsNotFound();
        }

        return $this->unlessDenied($user, $family, $this->mode->forEdit($user, $family, $member));
    }

    /** Changes that are applied at once or not at all (other names, for example). */
    public function updateDirectly(User $user, FamilyMember $member): Response
    {
        $family = $this->family($member);

        if (! $this->privacy->viewer($user, $family)->canView($member)) {
            return Response::denyAsNotFound();
        }

        return $this->mode->forEdit($user, $family, $member) === ChangeMode::DIRECT ? Response::allow() : Response::deny();
    }

    public function delete(User $user, FamilyMember $member): Response
    {
        $family = $this->family($member);

        if (! $this->privacy->viewer($user, $family)->canView($member)) {
            return Response::denyAsNotFound();
        }

        return $this->unlessDenied($user, $family, $this->mode->forDelete($user, $family));
    }

    /** Add links between people (directly or by proposal). */
    public function manageRelationships(User $user, Family $family): Response
    {
        return $this->unlessDenied($user, $family, $this->mode->forEdit($user, $family));
    }

    /** Change or remove existing links: there is no approval path for these. */
    public function manageRelationshipsDirectly(User $user, Family $family): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->mode->forEdit($user, $family) === ChangeMode::DIRECT ? Response::allow() : Response::deny();
    }

    private function unlessDenied(User $user, Family $family, string $mode): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $mode === ChangeMode::DENY ? Response::deny() : Response::allow();
    }

    private function family(FamilyMember $member): Family
    {
        return $member->relationLoaded('family') && $member->family !== null
            ? $member->family
            : $member->family()->withTrashed()->firstOrFail();
    }
}

<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Auth\Access\Response;

/**
 * Authorisation for a family and its settings.
 *
 * Someone who is not an active member gets "not found" rather than "forbidden", so the
 * existence of another family's records is never revealed
 * (docs/architecture/05-roles-permissions.md).
 *
 * Super Administrators get no special treatment here: platform staff cannot browse a
 * family's records without an owner-granted support grant (built in Phase 10).
 */
class FamilyPolicy
{
    public function __construct(private readonly FamilyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    /** Email verification is required before creating or joining a family. */
    public function create(User $user): Response
    {
        if (! $user->isActive()) {
            return Response::deny();
        }

        return $user->hasVerifiedEmail()
            ? Response::allow()
            : Response::deny(__('Verify your email address before creating a family.'));
    }

    public function view(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::TreeView);
    }

    public function update(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::FamilyUpdate);
    }

    public function delete(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::FamilyDelete);
    }

    public function exportGedcom(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::GedcomExport);
    }

    public function importGedcom(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::GedcomImport);
    }

    public function generateReports(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::ReportsGenerate);
    }

    public function transferOwnership(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::FamilyTransferOwnership);
    }

    public function grantSupportAccess(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::FamilyGrantSupportAccess);
    }

    public function invite(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::MembersInvite);
    }

    /** May the user give someone the given role in this family? Only roles below their own. */
    public function assignRole(User $user, Family $family, FamilyRole $role): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->canAssignRole($user, $family, $role)
            ? Response::allow()
            : Response::deny(__('You can only assign roles below your own.'));
    }

    /** The readable activity feed: Editors and above. */
    public function viewActivity(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::AuditViewRecords);
    }

    public function viewAudit(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::AuditView);
    }

    private function check(User $user, Family $family, FamilyPermission $permission): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, $permission)
            ? Response::allow()
            : Response::deny();
    }
}

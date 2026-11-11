<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Models\Family;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Auth\Access\Response;

/**
 * Timeline entries. Outsiders get "not found"; so does a member whose role may not see an
 * entry's visibility level. Adding and editing needs the `timeline.create` permission.
 */
class TimelineEntryPolicy
{
    public function __construct(private readonly FamilyAccess $access) {}

    public function viewAny(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::TreeView);
    }

    public function create(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::TimelineCreate);
    }

    public function update(User $user, TimelineEntry $entry): Response
    {
        $family = $entry->family()->withTrashed()->firstOrFail();

        if (! $this->access->can($user, $family, $entry->visibility->requiredPermission())) {
            return Response::denyAsNotFound();
        }

        return $this->check($user, $family, FamilyPermission::TimelineCreate);
    }

    public function delete(User $user, TimelineEntry $entry): Response
    {
        return $this->update($user, $entry);
    }

    private function check(User $user, Family $family, FamilyPermission $permission): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, $permission) ? Response::allow() : Response::deny();
    }
}

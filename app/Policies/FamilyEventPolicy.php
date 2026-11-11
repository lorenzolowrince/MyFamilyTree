<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Auth\Access\Response;

/**
 * Family events. Every member can see them; people who may add timeline entries (Editors and
 * above) may also schedule, change and cancel events.
 */
class FamilyEventPolicy
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

    public function update(User $user, FamilyEvent $event): Response
    {
        return $this->check($user, $event->family()->withTrashed()->firstOrFail(), FamilyPermission::TimelineCreate);
    }

    public function delete(User $user, FamilyEvent $event): Response
    {
        return $this->update($user, $event);
    }

    private function check(User $user, Family $family, FamilyPermission $permission): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, $permission) ? Response::allow() : Response::deny();
    }
}

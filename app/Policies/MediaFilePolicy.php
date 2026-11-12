<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Auth\Access\Response;

/**
 * Photos and documents (docs/architecture/05-roles-permissions.md, "Media").
 *
 * - Outsiders get "not found". So does a member whose role may not see a file's visibility
 *   level, and anyone but the uploader while a file is still in quarantine or was refused.
 * - Contributors and above upload. The uploader may always edit and delete their own file.
 * - Editors and above edit any file; the Owner and Administrators delete any file.
 */
abstract class MediaFilePolicy
{
    public function __construct(protected readonly FamilyAccess $access) {}

    public function viewAny(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::MediaView);
    }

    public function create(User $user, Family $family): Response
    {
        return $this->check($user, $family, FamilyPermission::MediaUpload);
    }

    public function view(User $user, FamilyPhoto|FamilyDocument $file): Response
    {
        return $this->visible($user, $file) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, FamilyPhoto|FamilyDocument $file): Response
    {
        return $this->own($user, $file, FamilyPermission::MediaEditAny);
    }

    public function delete(User $user, FamilyPhoto|FamilyDocument $file): Response
    {
        return $this->own($user, $file, FamilyPermission::MediaDeleteAny);
    }

    protected function visible(User $user, FamilyPhoto|FamilyDocument $file): bool
    {
        $family = $file->family()->withTrashed()->first();

        if ($family === null || ! $this->access->can($user, $family, FamilyPermission::MediaView)) {
            return false;
        }

        if ($file->uploaded_by === $user->id) {
            return true;
        }

        return $file->isReady() && $this->access->can($user, $family, $file->visibility->requiredPermission());
    }

    protected function own(User $user, FamilyPhoto|FamilyDocument $file, FamilyPermission $forAny): Response
    {
        if (! $this->visible($user, $file)) {
            return Response::denyAsNotFound();
        }

        $family = $file->family()->withTrashed()->firstOrFail();

        // Someone demoted to Viewer keeps seeing their uploads, but no longer manages them.
        $mine = $file->uploaded_by === $user->id && $this->access->can($user, $family, FamilyPermission::MediaUpload);

        return $mine || $this->access->can($user, $family, $forAny) ? Response::allow() : Response::deny();
    }

    protected function check(User $user, Family $family, FamilyPermission $permission): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, $permission) ? Response::allow() : Response::deny();
    }
}

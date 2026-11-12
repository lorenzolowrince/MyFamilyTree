<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Models\Family;
use App\Models\PhotoAlbum;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Auth\Access\Response;

/**
 * Albums. Everyone in the family sees the list (each photo inside still has its own
 * visibility). Whoever may upload can start an album; its creator and Editors and above can
 * rename or remove it.
 */
class PhotoAlbumPolicy
{
    public function __construct(private readonly FamilyAccess $access) {}

    public function create(User $user, Family $family): Response
    {
        if (! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        return $this->access->can($user, $family, FamilyPermission::MediaUpload) ? Response::allow() : Response::deny();
    }

    public function update(User $user, PhotoAlbum $album): Response
    {
        $family = $album->family()->withTrashed()->first();

        if ($family === null || ! $this->access->isMember($user, $family)) {
            return Response::denyAsNotFound();
        }

        $mine = $album->created_by === $user->id && $this->access->can($user, $family, FamilyPermission::MediaUpload);

        return $mine || $this->access->can($user, $family, FamilyPermission::MediaEditAny) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, PhotoAlbum $album): Response
    {
        return $this->update($user, $album);
    }
}

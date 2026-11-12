<?php

namespace App\Policies;

use App\Enums\FamilyPermission;
use App\Models\FamilyDocument;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Documents: the shared media rules, plus who may take a copy away. */
class FamilyDocumentPolicy extends MediaFilePolicy
{
    /**
     * Everyone who may see a document reads it on screen. Downloading the file itself is for
     * Editors and above, the uploader, and everyone else only when it is marked downloadable.
     */
    public function download(User $user, FamilyDocument $document): Response
    {
        if (! $this->visible($user, $document) || ! $document->isReady()) {
            return Response::denyAsNotFound();
        }

        $family = $document->family()->withTrashed()->firstOrFail();

        return $document->downloadable
            || $document->uploaded_by === $user->id
            || $this->access->can($user, $family, FamilyPermission::DocumentsDownloadAny)
                ? Response::allow()
                : Response::deny(__('This document can be read here but not downloaded.'));
    }
}

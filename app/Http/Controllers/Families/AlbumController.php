<?php

namespace App\Http\Controllers\Families;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\PhotoAlbum;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Photo albums: named groups of photos. Removing an album never removes its photos. */
class AlbumController extends Controller
{
    public function store(Request $request, Family $family): RedirectResponse
    {
        $this->authorize('create', [PhotoAlbum::class, $family]);

        /** @var User $user */
        $user = $request->user();

        $album = new PhotoAlbum;
        $album->family_id = $family->id;
        $album->created_by = $user->id;
        $this->fill($album, $request, $family)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Album ":title" created.', ['title' => $album->title])]);

        return to_route('families.photos.index', ['family' => $family, 'album' => $album->id]);
    }

    public function update(Request $request, Family $family, PhotoAlbum $album): RedirectResponse
    {
        $this->authorize('update', $album);

        $this->fill($album, $request, $family)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Album saved.')]);

        return back();
    }

    public function destroy(Family $family, PhotoAlbum $album): RedirectResponse
    {
        $this->authorize('delete', $album);

        // The photos stay in the gallery, outside any album.
        $album->photos()->update(['album_id' => null]);
        $album->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Album removed. Its photos are still in the gallery.')]);

        return to_route('families.photos.index', $family);
    }

    private function fill(PhotoAlbum $album, Request $request, Family $family): PhotoAlbum
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cover_photo_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('family_photos', 'id')->where('family_id', $family->id)->where('status', 'ready')->where('visibility', 'family')->whereNull('deleted_at'),
            ],
        ]);

        $album->title = trim((string) $data['title']);
        $album->description = isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null;

        if (array_key_exists('cover_photo_id', $data)) {
            $album->cover_photo_id = $data['cover_photo_id'] === null ? null : (string) $data['cover_photo_id'];
        }

        return $album;
    }
}

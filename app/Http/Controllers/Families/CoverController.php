<?php

namespace App\Http\Controllers\Families;

use App\Actions\Media\UploadMedia;
use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyPhoto;
use App\Models\User;
use App\Services\Media\MediaLibrary;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A family's cover image: one of its photos (`families.cover_photo_id`). An image uploaded
 * here goes through the same safety checks as every photo and also appears in the gallery.
 */
class CoverController extends Controller
{
    public function show(Family $family, MediaLibrary $library): StreamedResponse
    {
        $this->authorize('view', $family);

        $photo = self::photo($family);
        abort_if($photo === null, 404);

        return PhotoController::image($library, $photo->keyFor('display'));
    }

    public function store(Request $request, Family $family, UploadMedia $upload): RedirectResponse
    {
        $this->authorize('update', $family);

        $request->validate(['cover' => ['required', 'file']]);

        $file = $request->file('cover');
        abort_unless($file instanceof UploadedFile, 422);

        /** @var User $user */
        $user = $request->user();

        try {
            $photo = $upload->handle($family, $user, $file, 'photo', function (FamilyPhoto $photo) use ($family): void {
                $photo->caption = __('Cover of :family', ['family' => $family->name]);
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['cover' => $exception->getMessage()]);
        }

        $family->forceFill(['cover_photo_id' => $photo->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cover image uploaded. It appears once it has been checked.')]);

        return back();
    }

    /** Stops using the photo as the cover. The photo itself stays in the gallery. */
    public function destroy(Family $family): RedirectResponse
    {
        $this->authorize('update', $family);

        $family->forceFill(['cover_photo_id' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cover image removed.')]);

        return back();
    }

    public static function url(Family $family): ?string
    {
        $photo = self::photo($family);

        return $photo === null
            ? null
            : route('families.cover.show', ['family' => $family, 'v' => substr($photo->id, -8)]);
    }

    /** The cover, only if it is ready and something the whole family may see. */
    private static function photo(Family $family): ?FamilyPhoto
    {
        if ($family->cover_photo_id === null) {
            return null;
        }

        return FamilyPhoto::query()
            ->inFamily($family)
            ->whereKey($family->cover_photo_id)
            ->where('status', MediaStatus::Ready->value)
            ->where('visibility', 'family')
            ->first();
    }
}

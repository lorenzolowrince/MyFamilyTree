<?php

namespace App\Http\Controllers\Families;

use App\Http\Controllers\Controller;
use App\Models\Family;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A family's cover image.
 *
 * Temporary: kept on the server's private disk and served only to members of the family.
 * It moves to object storage, with scanning and re-encoding, when the media pipeline exists.
 */
class CoverController extends Controller
{
    private const DISK = 'local';

    private const TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function show(Family $family): StreamedResponse
    {
        $this->authorize('view', $family);

        $path = self::path($family);

        abort_if($path === null || ! Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, [
            'Content-Type' => self::TYPES[Str::lower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Family $family): RedirectResponse
    {
        $this->authorize('update', $family);

        $request->validate([
            'cover' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=100,max_width=8000,max_height=8000'],
        ]);

        $file = $request->file('cover');
        abort_unless($file instanceof UploadedFile, 422);

        // The stored name is never taken from the upload.
        $extension = array_search($file->getMimeType(), self::TYPES, true) ?: 'jpg';
        $path = $file->storeAs("covers/{$family->id}", Str::random(40).'.'.$extension, self::DISK);

        abort_if($path === false, 500);

        $this->forget($family);
        $family->forceFill(['settings' => [...$family->settings, 'cover_path' => $path]])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cover image updated.')]);

        return back();
    }

    public function destroy(Family $family): RedirectResponse
    {
        $this->authorize('update', $family);

        $this->forget($family);

        $settings = $family->settings;
        unset($settings['cover_path']);
        $family->forceFill(['settings' => $settings])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cover image removed.')]);

        return back();
    }

    public static function url(Family $family): ?string
    {
        $path = self::path($family);

        return $path === null
            ? null
            : route('families.cover.show', ['family' => $family, 'v' => substr(hash('sha256', $path), 0, 8)]);
    }

    private static function path(Family $family): ?string
    {
        $path = $family->settings['cover_path'] ?? null;

        // Only ever a path this controller generated for this family.
        return is_string($path) && Str::startsWith($path, "covers/{$family->id}/") && ! Str::contains($path, '..')
            ? $path
            : null;
    }

    private function forget(Family $family): void
    {
        $path = self::path($family);

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}

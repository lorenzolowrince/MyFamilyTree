<?php

namespace App\Http\Controllers\Families;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Models\TimelineEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Photos and documents attached to a timeline entry. */
class TimelineAttachmentController extends Controller
{
    public function store(Request $request, Family $family, TimelineEntry $entry): RedirectResponse
    {
        $this->authorize('update', $entry);

        $data = $request->validate([
            'photo_id' => ['required_without:document_id', 'prohibits:document_id', 'nullable', 'uuid'],
            'document_id' => ['required_without:photo_id', 'nullable', 'uuid'],
        ]);

        // Only a file this person may see can be attached; anything else "does not exist".
        $file = isset($data['photo_id'])
            ? FamilyPhoto::query()->inFamily($family)->whereKey((string) $data['photo_id'])->first()
            : FamilyDocument::query()->inFamily($family)->whereKey((string) ($data['document_id'] ?? ''))->first();

        if ($file === null || ! $file->isReady() || $request->user()?->cannot('view', $file)) {
            throw ValidationException::withMessages([isset($data['photo_id']) ? 'photo_id' : 'document_id' => __('That file could not be found.')]);
        }

        $column = $file instanceof FamilyPhoto ? 'photo_id' : 'document_id';

        DB::table('timeline_attachments')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'family_id' => $family->id,
            'timeline_id' => $entry->id,
            $column => $file->id,
            'created_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attached to ":title".', ['title' => $entry->title])]);

        return back();
    }

    public function destroy(Family $family, TimelineEntry $entry, string $attachment): RedirectResponse
    {
        $this->authorize('update', $entry);

        DB::table('timeline_attachments')
            ->where('family_id', $family->id)
            ->where('timeline_id', $entry->id)
            ->where('id', $attachment)
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attachment removed.')]);

        return back();
    }
}

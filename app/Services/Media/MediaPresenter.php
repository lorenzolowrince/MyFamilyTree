<?php

namespace App\Services\Media;

use App\Enums\FamilyPermission;
use App\Enums\MediaStatus;
use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyMember;
use App\Models\FamilyPhoto;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\Privacy\MemberViewer;
use Illuminate\Support\Facades\DB;

/**
 * The only way a photo or document leaves the server as data. File addresses are only given
 * for files that are ready; each address is checked again when the file is requested.
 *
 * Which profile photos exist is remembered for the length of one request (MediaMemo), so a
 * tree of 200 people costs one query, not 200.
 */
class MediaPresenter
{
    /** The small profile picture of a person, if the viewer may see that photo. */
    public function avatarUrl(FamilyMember $member, MemberViewer $viewer): ?string
    {
        if ($member->profile_photo_id === null) {
            return null;
        }

        $memo = app(MediaMemo::class);

        $memo->profilePhotos[$member->family_id] ??= DB::table('family_photos')
            ->where('family_id', $member->family_id)
            ->where('status', MediaStatus::Ready->value)
            ->whereNull('deleted_at')
            ->whereIn('id', DB::table('family_members')->where('family_id', $member->family_id)->whereNotNull('profile_photo_id')->select('profile_photo_id'))
            ->pluck('visibility', 'id')
            ->all();

        $visibility = $memo->profilePhotos[$member->family_id][$member->profile_photo_id] ?? null;

        return $visibility !== null && in_array($visibility, $viewer->visibleLevels(), true)
            ? $this->photoUrl($member->family_id, $member->profile_photo_id, 'thumb')
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function photo(FamilyPhoto $photo, MemberViewer $viewer, User $user): array
    {
        $ready = $photo->isReady();

        return [
            'id' => $photo->id,
            'caption' => $photo->caption,
            'description' => $photo->description,
            'taken' => $photo->taken->label(),
            'taken_input' => $photo->taken->toArray(),
            'taken_year' => $photo->taken->yearValue(),
            'album_id' => $photo->album_id,
            'visibility' => $photo->visibility->value,
            'visibility_label' => $photo->visibility->label(),
            ...$this->common($photo, $viewer, $user),
            'urls' => ! $ready ? null : [
                'thumb' => $this->photoUrl($photo->family_id, $photo->id, 'thumb'),
                'display' => $this->photoUrl($photo->family_id, $photo->id, 'display'),
                'original' => $this->photoUrl($photo->family_id, $photo->id, 'original'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(FamilyDocument $document, MemberViewer $viewer, User $user): array
    {
        $ready = $document->isReady();
        $slug = $this->slug($document->family_id);
        $pages = [];

        if ($ready) {
            foreach (array_keys($document->pageKeys()) as $index) {
                $pages[] = route('families.documents.page', ['family' => $slug, 'document' => $document->id, 'page' => $index + 1]);
            }
        }

        $mayDownload = $ready && ($document->downloadable || $document->uploaded_by === $user->id || $viewer->has(FamilyPermission::DocumentsDownloadAny));

        return [
            'id' => $document->id,
            'title' => $document->title ?? $document->original_filename,
            'description' => $document->description,
            'category' => $document->category->value,
            'category_label' => $document->category->label(),
            'issued' => $document->issued->label(),
            'issued_input' => $document->issued->toArray(),
            'page_count' => $document->page_count,
            'is_pdf' => $document->mime_type === 'application/pdf',
            'downloadable' => $document->downloadable,
            'visibility' => $document->visibility->value,
            'visibility_label' => $document->visibility->label(),
            ...$this->common($document, $viewer, $user),
            'urls' => ! $ready ? null : [
                'thumb' => route('families.documents.thumb', ['family' => $slug, 'document' => $document->id]),
                'pages' => $pages,
                'download' => $mayDownload ? route('families.documents.download', ['family' => $slug, 'document' => $document->id]) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function common(FamilyPhoto|FamilyDocument $file, MemberViewer $viewer, User $user): array
    {
        $mine = $file->uploaded_by === $user->id;
        $manages = $mine && $viewer->has(FamilyPermission::MediaUpload);

        return [
            'status' => $file->status->value,
            'rejection_reason' => $mine ? $file->rejection_reason : null,
            'filename' => $file->original_filename,
            'size_bytes' => $file->size_bytes,
            'width' => $file->width,
            'height' => $file->height,
            'uploaded_by' => $file->relationLoaded('uploader') ? ($file->uploader->name ?? null) : null,
            'uploaded_at' => $file->created_at?->toIso8601String(),
            'is_mine' => $mine,
            'can' => [
                'update' => $manages || $viewer->has(FamilyPermission::MediaEditAny),
                'delete' => $manages || $viewer->has(FamilyPermission::MediaDeleteAny),
            ],
        ];
    }

    /**
     * Photos and documents attached to timeline entries, limited to files the viewer may see.
     *
     * @param  list<string>  $entryIds
     * @return array<string, list<array{id: string, type: string, file_id: string, title: string|null, thumb_url: string, url: string}>>
     */
    public function timelineAttachments(string $familyId, array $entryIds, MemberViewer $viewer): array
    {
        if ($entryIds === []) {
            return [];
        }

        $slug = $this->slug($familyId);
        $found = [];

        foreach (['photo' => ['family_photos', 'photo_id', 'caption'], 'document' => ['family_documents', 'document_id', 'title']] as $type => [$table, $column, $label]) {
            $rows = DB::table('timeline_attachments')
                ->join($table, "{$table}.id", '=', "timeline_attachments.{$column}")
                ->where('timeline_attachments.family_id', $familyId)
                ->whereIn('timeline_attachments.timeline_id', $entryIds)
                ->where("{$table}.status", MediaStatus::Ready->value)
                ->whereNull("{$table}.deleted_at")
                ->whereIn("{$table}.visibility", $viewer->visibleLevels())
                ->orderBy('timeline_attachments.created_at')
                ->get(['timeline_attachments.id', 'timeline_attachments.timeline_id', "{$table}.id as file_id", "{$table}.{$label} as title", "{$table}.original_filename"]);

            foreach ($rows as $row) {
                $fileId = (string) $row->file_id;

                $found[(string) $row->timeline_id][] = [
                    'id' => (string) $row->id,
                    'type' => $type,
                    'file_id' => $fileId,
                    'title' => $row->title ?? $row->original_filename,
                    'thumb_url' => $type === 'photo'
                        ? $this->photoUrl($familyId, $fileId, 'thumb')
                        : route('families.documents.thumb', ['family' => $slug, 'document' => $fileId]),
                    'url' => $type === 'photo'
                        ? route('families.photos.show', ['family' => $slug, 'photo' => $fileId])
                        : route('families.documents.show', ['family' => $slug, 'document' => $fileId]),
                ];
            }
        }

        return $found;
    }

    /**
     * The timeline entries a file is attached to, and (for someone who may edit the timeline)
     * the entries it could be attached to.
     *
     * @return array{attached: list<array{id: string, entry_id: string, title: string}>, entries: list<array{id: string, title: string, year: int|null}>, can_attach: bool}
     */
    public function timelineLinks(FamilyPhoto|FamilyDocument $file, MemberViewer $viewer): array
    {
        $levels = $viewer->visibleLevels();
        $canAttach = $file->isReady() && $viewer->has(FamilyPermission::TimelineCreate);

        $attached = DB::table('timeline_attachments')
            ->join('family_timeline', 'family_timeline.id', '=', 'timeline_attachments.timeline_id')
            ->where('timeline_attachments.family_id', $file->family_id)
            ->where($file instanceof FamilyPhoto ? 'timeline_attachments.photo_id' : 'timeline_attachments.document_id', $file->id)
            ->whereNull('family_timeline.deleted_at')
            ->whereIn('family_timeline.visibility', $levels)
            ->orderBy('family_timeline.event_date')
            ->get(['timeline_attachments.id', 'family_timeline.id as entry_id', 'family_timeline.title'])
            ->map(fn (\stdClass $row): array => ['id' => (string) $row->id, 'entry_id' => (string) $row->entry_id, 'title' => (string) $row->title])
            ->all();

        $entries = ! $canAttach ? [] : TimelineEntry::query()
            ->where('family_id', $file->family_id)
            ->whereIn('visibility', $levels)
            ->orderByRaw('event_date DESC NULLS LAST')
            ->limit(300)
            ->get()
            ->map(fn (TimelineEntry $entry): array => ['id' => $entry->id, 'title' => $entry->title, 'year' => $entry->event->yearValue()])
            ->all();

        return ['attached' => array_values($attached), 'entries' => array_values($entries), 'can_attach' => $canAttach];
    }

    public function photoUrl(string $familyId, string $photoId, string $size): string
    {
        return route('families.photos.file', ['family' => $this->slug($familyId), 'photo' => $photoId, 'size' => $size]);
    }

    private function slug(string $familyId): string
    {
        return app(MediaMemo::class)->slugs[$familyId] ??= (string) Family::query()->withTrashed()->whereKey($familyId)->value('slug');
    }
}

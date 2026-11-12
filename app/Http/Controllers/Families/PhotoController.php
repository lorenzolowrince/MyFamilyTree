<?php

namespace App\Http\Controllers\Families;

use App\Actions\Media\SaveMediaDetails;
use App\Actions\Media\UploadMedia;
use App\Enums\FamilyPermission;
use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\MediaDetailsRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyPhoto;
use App\Models\PhotoAlbum;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Media\MediaLibrary;
use App\Services\Media\MediaPresenter;
use App\Services\Media\MediaProcessor;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Support\FormOptions;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A family's photo gallery. */
class PhotoController extends Controller
{
    public function __construct(
        private readonly MemberPrivacy $privacy,
        private readonly MediaPresenter $media,
        private readonly MemberPresenter $members,
        private readonly MediaLibrary $library,
        private readonly HistoryRecorder $history,
    ) {}

    public function index(Request $request, Family $family): Response
    {
        $this->authorize('viewAny', [FamilyPhoto::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $levels = $viewer->visibleLevels();

        $album = is_string($request->query('album')) ? $request->query('album') : null;
        $person = is_string($request->query('person')) ? $request->query('person') : null;
        $year = is_numeric($request->query('year')) ? (int) $request->query('year') : null;

        $subject = $person === null ? null : FamilyMember::query()->inFamily($family)->whereKey($person)->first();
        $subject = $subject !== null && $viewer->canView($subject) ? $subject : null;

        $photos = FamilyPhoto::query()
            ->inFamily($family)
            ->visibleTo($user, $levels)
            ->with('uploader')
            ->when($album === 'none', fn (Builder $query) => $query->whereNull('album_id'))
            ->when($album !== null && $album !== 'none', fn (Builder $query) => $query->where('album_id', $album))
            ->when($person !== null, fn (Builder $query) => $subject === null
                ? $query->whereRaw('false')
                : $query->whereHas('people', fn (Builder $people) => $people->where('family_members.id', $subject->id)))
            ->when($year !== null, fn (Builder $query) => $query->whereYear('taken_date', (int) $year))
            ->latest()
            ->orderByDesc('id')
            ->paginate(60)
            ->withQueryString()
            ->through(fn (FamilyPhoto $photo): array => $this->media->photo($photo, $viewer, $user));

        $counts = FamilyPhoto::query()->inFamily($family)
            ->where('status', MediaStatus::Ready->value)
            ->whereIn('visibility', $levels)
            ->whereNotNull('album_id')
            ->selectRaw('album_id, count(*) AS total')
            ->groupBy('album_id')
            ->pluck('total', 'album_id');

        $covers = FamilyPhoto::query()->inFamily($family)
            ->where('status', MediaStatus::Ready->value)
            ->whereIn('visibility', $levels)
            ->whereIn('id', PhotoAlbum::query()->inFamily($family)->whereNotNull('cover_photo_id')->select('cover_photo_id'))
            ->pluck('id')
            ->flip();

        $manages = $viewer->has(FamilyPermission::MediaEditAny);
        $uploads = $viewer->has(FamilyPermission::MediaUpload);

        return Inertia::render('photos/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'photos' => $photos,
            'albums' => PhotoAlbum::query()->inFamily($family)->orderBy('title')->get()->map(fn (PhotoAlbum $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'count' => (int) ($counts[$item->id] ?? 0),
                'cover_url' => $item->cover_photo_id !== null && $covers->has($item->cover_photo_id)
                    ? $this->media->photoUrl($family->id, $item->cover_photo_id, 'thumb')
                    : null,
                'can' => ['update' => $manages || ($uploads && $item->created_by === $user->id)],
            ]),
            'filters' => [
                'album' => $album,
                'year' => $year,
                'person' => $subject === null ? null : ['id' => $subject->id, 'name' => $subject->full_name],
            ],
            'years' => FamilyPhoto::query()->inFamily($family)
                ->where('status', MediaStatus::Ready->value)
                ->whereIn('visibility', $levels)
                ->whereNotNull('taken_date')
                ->selectRaw('DISTINCT extract(year FROM taken_date)::int AS year')
                ->orderByDesc('year')
                ->pluck('year'),
            'limits' => [
                'max_bytes' => (int) config('media.photos.max_bytes'),
                'max_files' => (int) config('media.max_files_per_upload'),
            ],
            'options' => ['visibilities' => self::visibilities($levels)],
            'can' => ['upload' => $uploads],
        ]);
    }

    public function store(MediaDetailsRequest $request, Family $family, UploadMedia $upload, SaveMediaDetails $details): RedirectResponse
    {
        $this->authorize('create', [FamilyPhoto::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);

        /** @var array<string, mixed> $data */
        $data = $request->safe()->only(['album_id', 'visibility', 'taken', 'member_ids']);

        /** @var list<string> $memberIds */
        $memberIds = array_values((array) ($data['member_ids'] ?? []));

        $files = array_values(array_filter((array) $request->file('files'), fn (mixed $file): bool => $file instanceof UploadedFile));
        $stored = 0;
        $problems = [];

        foreach ($files as $file) {
            try {
                $upload->handle($family, $user, $file, 'photo',
                    fn (FamilyPhoto $photo) => $details->fill($photo, $data, $viewer),
                    function (FamilyPhoto $photo) use ($details, $memberIds, $viewer): void {
                        if ($memberIds !== []) {
                            $details->syncPeople($photo, $memberIds, $viewer);
                        }
                    },
                );
                $stored++;
            } catch (DomainException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        if ($stored > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
                '{1} 1 photo uploaded. It appears once it has been checked.|[2,*] :count photos uploaded. They appear once they have been checked.',
                $stored,
            )]);
        }

        return $problems === [] ? back() : back()->withErrors(['files' => implode("\n", $problems)]);
    }

    public function show(Request $request, Family $family, FamilyPhoto $photo): Response
    {
        $this->authorize('view', $photo);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $photo->load('uploader');

        $people = $photo->people()->orderBy('full_name')->get()->filter(fn (FamilyMember $member): bool => $viewer->canView($member))->values();

        return Inertia::render('photos/show', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'photo' => [
                ...$this->media->photo($photo, $viewer, $user),
                'album' => $photo->album === null ? null : ['id' => $photo->album->id, 'title' => $photo->album->title],
                'people' => $people->map(fn (FamilyMember $member): array => [
                    ...$this->members->summary($member, $viewer),
                    'is_profile_photo' => $member->profile_photo_id === $photo->id,
                    'can_set_profile' => $photo->isReady() && $user->can('updateDirectly', $member),
                ])->all(),
                'is_family_cover' => $family->cover_photo_id === $photo->id,
            ],
            'albums' => PhotoAlbum::query()->inFamily($family)->orderBy('title')->get(['id', 'title'])
                ->map(fn (PhotoAlbum $item): array => ['id' => $item->id, 'title' => $item->title]),
            'options' => ['visibilities' => self::visibilities($viewer->visibleLevels()), 'qualifiers' => FormOptions::genealogy()['qualifiers']],
            'timeline' => $this->media->timelineLinks($photo, $viewer),
            'can' => ['setCover' => $photo->isReady() && $user->can('update', $family)],
        ]);
    }

    public function update(MediaDetailsRequest $request, Family $family, FamilyPhoto $photo, SaveMediaDetails $details): RedirectResponse
    {
        $this->authorize('update', $photo);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $details->fill($photo, $data, $viewer);
        $changes = $photo->getDirty();
        $photo->save();

        if (array_key_exists('member_ids', $data)) {
            /** @var list<string> $ids */
            $ids = array_values((array) $data['member_ids']);
            $added = $details->syncPeople($photo, $ids, $viewer);
            MediaProcessor::notifyTagged($photo, $added, $user);
        }

        if ($changes !== []) {
            $this->history->audit('photo.updated', $family->id, 'photo', $photo->id, null, $changes, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo saved.')]);

        return back();
    }

    public function destroy(Request $request, Family $family, FamilyPhoto $photo): RedirectResponse
    {
        $this->authorize('delete', $photo);

        /** @var User $user */
        $user = $request->user();

        if ($photo->status === MediaStatus::Rejected) {
            // Nothing is stored for a refused file: the record can simply go.
            $photo->forceDelete();
        } else {
            $photo->delete();
            $this->history->audit('photo.deleted', $family->id, 'photo', $photo->id, ['caption' => $photo->caption, 'filename' => $photo->original_filename], null, $user);
            $this->history->activity($family->id, 'photo.deleted', __('removed the photo ":name"', ['name' => (string) ($photo->caption ?? $photo->original_filename)]), 'photo', $photo->id, $photo->visibility->value, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed.')]);

        return to_route('families.photos.index', $family);
    }

    /** The image itself. Checked on every request; only ready files are served. */
    public function file(Family $family, FamilyPhoto $photo, string $size): StreamedResponse
    {
        $this->authorize('view', $photo);

        abort_unless($photo->isReady(), 404);

        return self::image($this->library, $photo->keyFor($size));
    }

    /** Use the photo as the profile picture of someone tagged in it. */
    public function profile(Request $request, Family $family, FamilyPhoto $photo): RedirectResponse
    {
        $this->authorize('view', $photo);
        abort_unless($photo->isReady(), 404);

        $data = $request->validate([
            'member_id' => ['required', 'uuid', Rule::exists('member_photos', 'member_id')->where('photo_id', $photo->id)],
            'remove' => ['sometimes', 'boolean'],
        ]);

        $member = FamilyMember::query()->inFamily($family)->whereKey((string) $data['member_id'])->firstOrFail();
        $this->authorize('updateDirectly', $member);

        $remove = (bool) ($data['remove'] ?? false);

        if ($remove && $member->profile_photo_id !== $photo->id) {
            return back();
        }

        // Not through UpdateMember: a picture is not a change to the person's recorded facts.
        $member->forceFill(['profile_photo_id' => $remove ? null : $photo->id])->saveQuietly();
        $photo->people()->updateExistingPivot($member->id, ['is_primary' => ! $remove]);

        /** @var User $user */
        $user = $request->user();
        $this->history->audit('member.profile_photo_changed', $family->id, 'member', $member->id, null, ['photo_id' => $remove ? null : $photo->id], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $remove
            ? __('Profile picture removed.')
            : __('This is now the profile picture of :name.', ['name' => $member->full_name])]);

        return back();
    }

    /** Use the photo as the family's cover image. */
    public function cover(Request $request, Family $family, FamilyPhoto $photo): RedirectResponse
    {
        $this->authorize('update', $family);
        $this->authorize('view', $photo);
        abort_unless($photo->isReady(), 404);

        // Everyone in the family sees the cover, whatever their role.
        if ($photo->visibility->value !== 'family') {
            return back()->withErrors(['cover' => __('Only a photo the whole family can see can be the cover.')]);
        }

        $family->forceFill(['cover_photo_id' => $photo->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cover image updated.')]);

        return back();
    }

    public static function image(MediaLibrary $library, string $key): StreamedResponse
    {
        $disk = $library->disk();

        abort_unless($disk->exists($key), 404);

        return $disk->response($key, null, [
            'Content-Type' => 'image/jpeg',
            // The address is tied to one file whose content never changes.
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @param  list<string>  $levels
     * @return list<array{value: string, label: string}>
     */
    public static function visibilities(array $levels): array
    {
        return array_values(array_filter(FormOptions::genealogy()['visibilities'], fn (array $option): bool => in_array($option['value'], $levels, true)));
    }
}

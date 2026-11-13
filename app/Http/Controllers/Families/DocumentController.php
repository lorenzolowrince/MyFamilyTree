<?php

namespace App\Http\Controllers\Families;

use App\Actions\Media\SaveMediaDetails;
use App\Actions\Media\UploadMedia;
use App\Enums\DocumentCategory;
use App\Enums\FamilyPermission;
use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\MediaDetailsRequest;
use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Media\MediaLibrary;
use App\Services\Media\MediaPresenter;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Support\FormOptions;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A family's documents: certificates, letters, land and religious records. */
class DocumentController extends Controller
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
        $this->authorize('viewAny', [FamilyDocument::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $levels = $viewer->visibleLevels();

        $category = DocumentCategory::tryFrom((string) $request->query('category', ''));
        $person = is_string($request->query('person')) ? $request->query('person') : null;
        $search = trim((string) $request->query('q', ''));

        $subject = $person === null ? null : FamilyMember::query()->inFamily($family)->whereKey($person)->first();
        $subject = $subject !== null && $viewer->canView($subject) ? $subject : null;

        $documents = FamilyDocument::query()
            ->inFamily($family)
            ->visibleTo($user, $levels)
            ->with('uploader')
            ->when($category !== null, fn (Builder $query) => $query->where('category', $category?->value))
            ->when($person !== null, fn (Builder $query) => $subject === null
                ? $query->whereRaw('false')
                : $query->whereHas('people', fn (Builder $people) => $people->where('family_members.id', $subject->id)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('description', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('original_filename', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->latest()
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (FamilyDocument $document): array => $this->media->document($document, $viewer, $user));

        return Inertia::render('documents/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'documents' => $documents,
            'filters' => [
                'category' => $category?->value,
                'q' => $search,
                'person' => $subject === null ? null : ['id' => $subject->id, 'name' => $subject->full_name],
            ],
            'limits' => [
                'max_bytes' => (int) config('media.documents.max_bytes'),
                'max_files' => (int) config('media.max_files_per_upload'),
            ],
            'options' => self::options($levels),
            'can' => ['upload' => $viewer->has(FamilyPermission::MediaUpload)],
        ]);
    }

    public function store(MediaDetailsRequest $request, Family $family, UploadMedia $upload, SaveMediaDetails $details): RedirectResponse|JsonResponse
    {
        $this->authorize('create', [FamilyDocument::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);

        /** @var array<string, mixed> $data */
        $data = $request->safe()->only(['category', 'visibility', 'downloadable', 'issued', 'member_ids']);

        /** @var list<string> $memberIds */
        $memberIds = array_values((array) ($data['member_ids'] ?? []));

        $files = array_values(array_filter((array) $request->file('files'), fn (mixed $file): bool => $file instanceof UploadedFile));
        $stored = 0;
        $problems = [];

        foreach ($files as $file) {
            try {
                $upload->handle($family, $user, $file, 'document',
                    function (FamilyDocument $document) use ($details, $data, $viewer): void {
                        $details->fill($document, $data, $viewer);
                        // Until someone gives it a better one, the title is the file's name without its ending.
                        $document->title = pathinfo((string) $document->original_filename, PATHINFO_FILENAME) ?: $document->original_filename;
                    },
                    function (FamilyDocument $document) use ($details, $memberIds, $viewer): void {
                        if ($memberIds !== []) {
                            $details->syncPeople($document, $memberIds, $viewer);
                        }
                    },
                );
                $stored++;
            } catch (DomainException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        // The uploader on the page sends one file at a time and shows its own summary.
        if ($request->expectsJson()) {
            return response()->json(['stored' => $stored, 'problems' => $problems], $stored === 0 && $problems !== [] ? 422 : 200);
        }

        if ($stored > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
                '{1} 1 document uploaded. It appears once it has been checked.|[2,*] :count documents uploaded. They appear once they have been checked.',
                $stored,
            )]);
        }

        return $problems === [] ? back() : back()->withErrors(['files' => implode("\n", $problems)]);
    }

    public function show(Request $request, Family $family, FamilyDocument $document): Response
    {
        $this->authorize('view', $document);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $document->load('uploader');

        return Inertia::render('documents/show', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'document' => [
                ...$this->media->document($document, $viewer, $user),
                'people' => $document->people()->orderBy('full_name')->get()
                    ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
                    ->map(fn (FamilyMember $member): array => $this->members->summary($member, $viewer))
                    ->values()
                    ->all(),
            ],
            'timeline' => $this->media->timelineLinks($document, $viewer),
            'options' => self::options($viewer->visibleLevels()),
        ]);
    }

    public function update(MediaDetailsRequest $request, Family $family, FamilyDocument $document, SaveMediaDetails $details): RedirectResponse
    {
        $this->authorize('update', $document);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $details->fill($document, $data, $viewer);
        $changes = $document->getDirty();
        $document->save();

        if (array_key_exists('member_ids', $data)) {
            /** @var list<string> $ids */
            $ids = array_values((array) $data['member_ids']);
            $details->syncPeople($document, $ids, $viewer);
        }

        if ($changes !== []) {
            $this->history->audit('document.updated', $family->id, 'document', $document->id, null, $changes, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document saved.')]);

        return back();
    }

    public function destroy(Request $request, Family $family, FamilyDocument $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        /** @var User $user */
        $user = $request->user();

        if ($document->status === MediaStatus::Rejected) {
            $document->forceDelete();
        } else {
            $document->delete();
            $this->history->audit('document.deleted', $family->id, 'document', $document->id, ['title' => $document->title, 'filename' => $document->original_filename], null, $user);
            $this->history->activity($family->id, 'document.deleted', __('removed the document ":name"', ['name' => (string) ($document->title ?? $document->original_filename)]), 'document', $document->id, $document->visibility->value, $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document removed.')]);

        return to_route('families.documents.index', $family);
    }

    public function thumb(Family $family, FamilyDocument $document): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_unless($document->isReady() && is_string($document->variants['thumb'] ?? null), 404);

        return PhotoController::image($this->library, (string) $document->variants['thumb']);
    }

    /** One page of the document as an image: how documents are read on screen. */
    public function page(Family $family, FamilyDocument $document, int $page): StreamedResponse
    {
        $this->authorize('view', $document);

        $key = $document->pageKeys()[$page - 1] ?? null;
        abort_unless($document->isReady() && $key !== null, 404);

        return PhotoController::image($this->library, $key);
    }

    /**
     * The file itself, always as a download: a PDF is never opened inside the application's
     * own address, where anything active in it could act as the signed-in person.
     */
    public function download(Request $request, Family $family, FamilyDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        $disk = $this->library->disk();
        abort_unless($disk->exists($document->storage_key), 404);

        /** @var User $user */
        $user = $request->user();
        $this->history->audit('document.downloaded', $family->id, 'document', $document->id, null, ['title' => $document->title], $user);

        return $disk->download($document->storage_key, $document->downloadName(), [
            'Content-Type' => $document->mime_type === 'application/pdf' ? 'application/pdf' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @param  list<string>  $levels
     * @return array<string, mixed>
     */
    private static function options(array $levels): array
    {
        return [
            'visibilities' => PhotoController::visibilities($levels),
            'qualifiers' => FormOptions::genealogy()['qualifiers'],
            'categories' => array_map(fn (DocumentCategory $case): array => ['value' => $case->value, 'label' => $case->label()], DocumentCategory::cases()),
        ];
    }
}

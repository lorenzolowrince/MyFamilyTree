<?php

namespace App\Http\Controllers\Families;

use App\Enums\TimelineCategory;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\TimelineEntryRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Place;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\History\TimelineBuilder;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Support\FormOptions;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** The family timeline: recorded history merged with births, deaths and marriages. */
class TimelineController extends Controller
{
    public function index(Request $request, Family $family, TimelineBuilder $timeline, MemberPrivacy $privacy, MemberPresenter $presenter): Response
    {
        $this->authorize('viewAny', [TimelineEntry::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $privacy->viewer($user, $family);

        $categories = [...array_column(TimelineCategory::cases(), 'value'), ...TimelineBuilder::DERIVED];
        $category = in_array($request->query('category'), $categories, true) ? (string) $request->query('category') : null;
        $memberId = is_string($request->query('member')) && Str::isUuid($request->query('member')) ? (string) $request->query('member') : null;

        $member = $memberId === null ? null : FamilyMember::query()->inFamily($family)->find($memberId);

        // Filtering by a person the viewer may not see shows nothing rather than revealing them.
        if ($member !== null && ! $viewer->canView($member)) {
            $member = null;
            $memberId = '00000000-0000-0000-0000-000000000000';
        }

        $filters = [
            'category' => $category,
            'from' => $this->year($request, 'from'),
            'to' => $this->year($request, 'to'),
            'member' => $memberId,
        ];

        return Inertia::render('timeline/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            ...$timeline->build($family, $viewer, $filters),
            'filters' => [...$filters, 'member' => $member === null ? null : $presenter->summary($member, $viewer)],
            'can' => ['create' => $user->can('create', [TimelineEntry::class, $family])],
            'options' => [
                ...FormOptions::genealogy(),
                'categories' => array_map(fn (TimelineCategory $case): array => ['value' => $case->value, 'label' => $case->label()], TimelineCategory::cases()),
            ],
        ]);
    }

    public function store(TimelineEntryRequest $request, Family $family): RedirectResponse
    {
        $this->authorize('create', [TimelineEntry::class, $family]);

        /** @var User $user */
        $user = $request->user();

        $entry = new TimelineEntry;
        $entry->family_id = $family->id;
        $entry->created_by = $user->id;

        $this->save($entry, $family, $user, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to the timeline.')]);

        return back();
    }

    public function update(TimelineEntryRequest $request, Family $family, TimelineEntry $entry): RedirectResponse
    {
        $this->authorize('update', $entry);

        /** @var User $user */
        $user = $request->user();

        $this->save($entry, $family, $user, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Timeline entry saved.')]);

        return back();
    }

    public function destroy(Family $family, TimelineEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        $entry->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Timeline entry removed.')]);

        return back();
    }

    private function save(TimelineEntry $entry, Family $family, User $user, TimelineEntryRequest $request): void
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $viewer = app(MemberPrivacy::class)->viewer($user, $family);

        DB::transaction(function () use ($entry, $family, $user, $data, $viewer): void {
            $entry->title = trim((string) $data['title']);
            $entry->category = TimelineCategory::from((string) $data['category']);
            $entry->description = isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null;
            $entry->event = FuzzyDate::fromInput(is_array($data['event'] ?? null) ? $data['event'] : null);
            $entry->place_id = Place::resolve($family, isset($data['place']) ? (string) $data['place'] : null)?->id;
            $entry->updated_by = $user->id;

            // Nobody can hide an entry at a level they could not then see themselves.
            if (isset($data['visibility']) && in_array($data['visibility'], $viewer->visibleLevels(), true)) {
                $entry->visibility = Visibility::from((string) $data['visibility']);
            }

            $entry->save();

            /** @var list<string> $ids */
            $ids = $data['participant_ids'] ?? [];

            // People the editor may not see cannot be attached, and ones already attached stay.
            $visible = FamilyMember::query()->inFamily($family)->whereIn('id', $ids)->get()
                ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
                ->modelKeys();

            $hidden = $entry->participants()->get()
                ->reject(fn (FamilyMember $member): bool => $viewer->canView($member))
                ->modelKeys();

            $sync = [];

            foreach ([...$visible, ...$hidden] as $id) {
                $sync[$id] = ['family_id' => $family->id];
            }

            $entry->participants()->sync($sync);
        });
    }

    private function year(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) && (int) $value > 0 && (int) $value < 3000 ? (int) $value : null;
    }
}

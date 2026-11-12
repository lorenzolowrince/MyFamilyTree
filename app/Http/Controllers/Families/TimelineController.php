<?php

namespace App\Http\Controllers\Families;

use App\Actions\Timeline\SaveTimelineEntry;
use App\Enums\TimelineCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\TimelineEntryRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\Collaboration\ChangeMode;
use App\Services\Collaboration\ChangeRequests;
use App\Services\History\TimelineBuilder;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Support\FormOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        if (app(ChangeMode::class)->forEdit($user, $family) === ChangeMode::PROPOSE) {
            /** @var array<string, mixed> $data */
            $data = $request->validated();
            unset($data['visibility']);
            app(ChangeRequests::class)->proposeTimelineEntry($family, $user, $data);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent for approval. It will appear on the timeline once a reviewer approves it.')]);

            return back();
        }

        $this->save(new TimelineEntry, $family, $user, $request);

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

        app(SaveTimelineEntry::class)->handle($entry, $family, $user, $data, app(MemberPrivacy::class)->viewer($user, $family));
    }

    private function year(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) && (int) $value > 0 && (int) $value < 3000 ? (int) $value : null;
    }
}

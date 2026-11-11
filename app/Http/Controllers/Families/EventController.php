<?php

namespace App\Http\Controllers\Families;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\FamilyEventRequest;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Support\FuzzyDate\FuzzyDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Upcoming family gatherings and announcements. */
class EventController extends Controller
{
    public function index(Request $request, Family $family): Response
    {
        $this->authorize('viewAny', [FamilyEvent::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $canManage = $user->can('create', [FamilyEvent::class, $family]);

        $present = fn (FamilyEvent $event): array => [
            'id' => $event->id,
            'title' => $event->title,
            'type' => $event->type->value,
            'type_label' => $event->type->label(),
            'description' => $event->description,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            // The same moments as the person typed them, for the edit form.
            'starts_local' => $event->starts_at->setTimezone($user->timezone)->format($event->all_day ? 'Y-m-d' : 'Y-m-d\TH:i'),
            'ends_local' => $event->ends_at?->setTimezone($user->timezone)->format($event->all_day ? 'Y-m-d' : 'Y-m-d\TH:i'),
            'all_day' => $event->all_day,
            'location_text' => $event->location_text,
            'notify_members' => $event->notify_members,
            'happened' => $event->hasHappened(),
            'on_timeline' => $event->timeline_id !== null,
        ];

        $events = FamilyEvent::query()->where('family_id', $family->id);

        return Inertia::render('events/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'upcoming' => (clone $events)
                ->where(fn ($query) => $query->where('starts_at', '>=', now())->orWhere('ends_at', '>=', now()))
                ->orderBy('starts_at')->limit(100)->get()->map($present)->all(),
            'past' => (clone $events)
                ->where('starts_at', '<', now())
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '<', now()))
                ->orderByDesc('starts_at')->limit(50)->get()->map($present)->all(),
            'can' => ['manage' => $canManage],
            'types' => array_map(fn (EventType $case): array => ['value' => $case->value, 'label' => $case->label()], EventType::cases()),
        ]);
    }

    public function store(FamilyEventRequest $request, Family $family): RedirectResponse
    {
        $this->authorize('create', [FamilyEvent::class, $family]);

        /** @var User $user */
        $user = $request->user();

        $event = new FamilyEvent;
        $event->family_id = $family->id;
        $event->created_by = $user->id;
        $this->fill($event, $request, $user)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event added.')]);

        return back();
    }

    public function update(FamilyEventRequest $request, Family $family, FamilyEvent $event): RedirectResponse
    {
        $this->authorize('update', $event);

        /** @var User $user */
        $user = $request->user();

        $this->fill($event, $request, $user)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event saved.')]);

        return back();
    }

    public function destroy(Family $family, FamilyEvent $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        $event->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event removed.')]);

        return back();
    }

    /** After an event has happened, keep it as part of the family's history. */
    public function convert(Request $request, Family $family, FamilyEvent $event): RedirectResponse
    {
        $this->authorize('update', $event);
        $this->authorize('create', [TimelineEntry::class, $family]);

        /** @var User $user */
        $user = $request->user();

        abort_unless($event->hasHappened(), 422, __('Only events that have already happened can be added to the timeline.'));
        abort_if($event->timeline_id !== null, 422, __('This event is already on the timeline.'));

        DB::transaction(function () use ($event, $family, $user): void {
            $day = $event->starts_at->setTimezone($user->timezone);

            $entry = new TimelineEntry;
            $entry->family_id = $family->id;
            $entry->category = $event->type->timelineCategory();
            $entry->title = $event->title;
            $entry->description = trim(implode("\n\n", array_filter([$event->description, $event->location_text])) ?: '') ?: null;
            $entry->event = FuzzyDate::day($day->year, $day->month, $day->day);
            $entry->created_by = $user->id;
            $entry->updated_by = $user->id;
            $entry->save();

            $event->forceFill(['timeline_id' => $entry->id])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to the family timeline.')]);

        return back();
    }

    private function fill(FamilyEvent $event, FamilyEventRequest $request, User $user): FamilyEvent
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $allDay = (bool) ($data['all_day'] ?? false);

        $parse = fn (string $value, bool $end): CarbonImmutable => $allDay
            ? ($end
                ? CarbonImmutable::parse(substr($value, 0, 10), $user->timezone)->endOfDay()
                : CarbonImmutable::parse(substr($value, 0, 10), $user->timezone)->startOfDay())
            : CarbonImmutable::parse($value, $user->timezone);

        $event->title = trim((string) $data['title']);
        $event->type = EventType::from((string) $data['type']);
        $event->description = isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null;
        $event->all_day = $allDay;
        $event->starts_at = $parse((string) $data['starts_at'], false)->utc();
        $event->ends_at = isset($data['ends_at']) ? $parse((string) $data['ends_at'], true)->utc() : null;
        $event->location_text = isset($data['location_text']) && trim((string) $data['location_text']) !== '' ? trim((string) $data['location_text']) : null;
        $event->notify_members = (bool) ($data['notify_members'] ?? true);

        return $event;
    }
}

<?php

use App\Enums\FamilyRole;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Models\FamilyEvent;
use App\Models\MemberName;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Services\History\FamilyOverview;
use App\Support\FuzzyDate\FuzzyDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Titles on the timeline page, in order. */
function timelineTitles($test, $family, array $query = []): array
{
    $page = $test->get(route('families.timeline.index', [$family, ...$query]))->assertOk()->viewData('page');

    return array_column($page['props']['items'], 'title');
}

// --- Other names -----------------------------------------------------------------------

test('other names can be added and removed, and are found by search', function () {
    [$family, $owner] = treeFamily();
    $person = person($family, 'Maria Gimbang');

    $this->actingAs($owner)
        ->post(route('families.members.names.store', [$family, $person]), ['name' => 'Maria binti Abdullah', 'type' => 'married'])
        ->assertSessionHasNoErrors();

    $name = MemberName::query()->sole();

    $this->getJson(route('families.members.lookup', [$family, 'search' => 'abdullah']))
        ->assertJsonPath('members.0.name', 'Maria Gimbang');

    $this->get(route('families.members.show', [$family, $person]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('names.0.name', 'Maria binti Abdullah')->where('names.0.type_label', 'Married name'));

    $this->delete(route('families.members.names.destroy', [$family, $person, $name]))->assertRedirect();

    expect(MemberName::query()->count())->toBe(0);
});

test('only people who may edit a person can change their names, and names stay inside their family', function () {
    [$family, $owner] = treeFamily();
    [$other, $outsider] = treeFamily();
    $person = person($family, 'Maria');
    $stranger = person($other, 'Stranger');

    $this->actingAs($owner)->post(route('families.members.names.store', [$family, $person]), ['name' => 'Mary', 'type' => 'alias']);
    $name = MemberName::query()->sole();

    $this->actingAs(memberWithRole($family, FamilyRole::Viewer))
        ->post(route('families.members.names.store', [$family, $person]), ['name' => 'Nope', 'type' => 'alias'])
        ->assertForbidden();

    $this->actingAs($outsider);
    $this->post(route('families.members.names.store', [$family, $person]), ['name' => 'Nope', 'type' => 'alias'])->assertNotFound();
    $this->delete(route('families.members.names.destroy', [$family, $person, $name]))->assertNotFound();
    // Another family's name under their own person's address does not resolve either.
    $this->delete(route('families.members.names.destroy', [$other, $stranger, $name]))->assertNotFound();

    expect(MemberName::query()->count())->toBe(1);
});

// --- Timeline --------------------------------------------------------------------------

test('the timeline merges recorded entries with births, deaths and marriages in date order', function () {
    [$family, $owner] = treeFamily();
    $husband = person($family, 'Gimbang', ['living_status' => LivingStatus::Deceased, 'birth' => FuzzyDate::year(1925), 'death' => FuzzyDate::year(1999)]);
    $wife = person($family, 'Sarah', ['living_status' => LivingStatus::Deceased, 'birth' => FuzzyDate::year(1930)]);
    partners($husband, $wife, ['start' => ['year' => 1950]]);

    $this->actingAs($owner)->post(route('families.timeline.store', $family), [
        'title' => 'Moved to Kota Kinabalu',
        'category' => 'migration',
        'event' => ['year' => 1960, 'qualifier' => 'about'],
        'place' => 'Kota Kinabalu',
        'participant_ids' => [$husband->id, $wife->id],
    ])->assertSessionHasNoErrors();

    $this->post(route('families.timeline.store', $family), ['title' => 'A story with no date', 'category' => 'other'])->assertSessionHasNoErrors();

    expect(timelineTitles($this, $family))->toBe([
        'Gimbang was born',
        'Sarah was born',
        'Gimbang and Sarah: marriage',
        'Moved to Kota Kinabalu',
        'Gimbang died',
        'A story with no date',
    ]);

    expect(timelineTitles($this, $family, ['category' => 'marriage']))->toBe(['Gimbang and Sarah: marriage'])
        ->and(timelineTitles($this, $family, ['category' => 'migration']))->toBe(['Moved to Kota Kinabalu'])
        ->and(timelineTitles($this, $family, ['from' => 1940, 'to' => 1970]))->toBe(['Gimbang and Sarah: marriage', 'Moved to Kota Kinabalu'])
        ->and(timelineTitles($this, $family, ['member' => $wife->id]))->toBe(['Sarah was born', 'Gimbang and Sarah: marriage', 'Moved to Kota Kinabalu']);

    $entry = TimelineEntry::query()->where('title', 'Moved to Kota Kinabalu')->sole();

    expect($entry->participants()->count())->toBe(2)
        ->and($entry->place?->name)->toBe('Kota Kinabalu')
        ->and($entry->event->label())->toBe('about 1960');
});

test('timeline entries can be edited and removed by editors, not by contributors or viewers', function (FamilyRole $role, bool $allowed) {
    [$family, $owner] = treeFamily();
    $this->actingAs($owner)->post(route('families.timeline.store', $family), ['title' => 'Reunion', 'category' => 'reunion']);
    $entry = TimelineEntry::query()->sole();

    $this->actingAs(memberWithRole($family, $role));
    $this->get(route('families.timeline.index', $family))->assertOk();

    $check = fn ($response) => $allowed ? $response->assertRedirect() : $response->assertForbidden();

    $check($this->post(route('families.timeline.store', $family), ['title' => 'Another', 'category' => 'other']));
    $check($this->patch(route('families.timeline.update', [$family, $entry]), ['title' => 'Renamed', 'category' => 'reunion']));
    $check($this->delete(route('families.timeline.destroy', [$family, $entry])));

    expect(TimelineEntry::query()->whereKey($entry->id)->exists())->toBe(! $allowed);
})->with([
    'editor' => [FamilyRole::Editor, true],
    'contributor' => [FamilyRole::Contributor, false],
    'viewer' => [FamilyRole::Viewer, false],
]);

test('the timeline respects privacy: living people\'s dates, hidden people and restricted entries', function () {
    [$family, $owner] = treeFamily();
    $living = person($family, 'Living Aunt', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::year(1975)]);
    $late = person($family, 'Late Uncle', ['living_status' => LivingStatus::Deceased, 'birth' => FuzzyDate::year(1920)]);
    $secret = person($family, 'Secret Cousin', ['living_status' => LivingStatus::Deceased, 'birth' => FuzzyDate::year(1950), 'visibility' => Visibility::Private]);
    partners($living, $late, ['start' => ['year' => 1995]]);

    $this->actingAs($owner);
    $this->post(route('families.timeline.store', $family), ['title' => 'Open entry', 'category' => 'other', 'event' => ['year' => 1980], 'participant_ids' => [$late->id, $secret->id]]);
    $this->post(route('families.timeline.store', $family), ['title' => 'Editors only', 'category' => 'other', 'event' => ['year' => 1981], 'visibility' => 'restricted']);

    expect(timelineTitles($this, $family))->toHaveCount(6);

    $this->actingAs(memberWithRole($family, FamilyRole::Viewer));

    // No birth of the living aunt, no marriage involving her, no secret cousin, no restricted entry.
    expect(timelineTitles($this, $family))->toBe(['Late Uncle was born', 'Open entry'])
        // Filtering by someone hidden shows nothing.
        ->and(timelineTitles($this, $family, ['member' => $secret->id]))->toBe([]);

    $this->get(route('families.timeline.index', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('items.1.people', 1)
        ->where('items.1.people.0.name', 'Late Uncle'));

    $restricted = TimelineEntry::query()->where('title', 'Editors only')->sole();
    $this->patch(route('families.timeline.update', [$family, $restricted]), ['title' => 'x', 'category' => 'other'])->assertNotFound();
});

test('editing an entry keeps participants the editor cannot see', function () {
    [$family, $owner] = treeFamily();
    $open = person($family, 'Open');
    $secret = person($family, 'Secret', ['visibility' => Visibility::Private]);

    $this->actingAs($owner)->post(route('families.timeline.store', $family), ['title' => 'Entry', 'category' => 'other', 'participant_ids' => [$open->id, $secret->id]]);
    $entry = TimelineEntry::query()->sole();

    $this->actingAs(memberWithRole($family, FamilyRole::Editor))
        ->patch(route('families.timeline.update', [$family, $entry]), ['title' => 'Entry', 'category' => 'other', 'participant_ids' => [$secret->id]])
        ->assertSessionHasNoErrors();

    // The editor removed "Open", could not add or remove "Secret".
    expect($entry->participants()->pluck('family_members.id')->all())->toBe([$secret->id]);
});

test('timeline and event routes are "not found" for outsiders and for another family\'s records', function () {
    [$family, $owner] = treeFamily();
    [$other, $outsider] = treeFamily();
    $this->actingAs($owner);
    $this->post(route('families.timeline.store', $family), ['title' => 'Entry', 'category' => 'other']);
    $this->post(route('families.events.store', $family), ['title' => 'Reunion', 'type' => 'reunion', 'starts_at' => '2030-01-01T10:00']);
    $entry = TimelineEntry::query()->sole();
    $event = FamilyEvent::query()->sole();

    $this->actingAs($outsider);
    $this->get(route('families.timeline.index', $family))->assertNotFound();
    $this->post(route('families.timeline.store', $family), ['title' => 'x', 'category' => 'other'])->assertNotFound();
    $this->patch(route('families.timeline.update', [$family, $entry]), ['title' => 'x', 'category' => 'other'])->assertNotFound();
    $this->delete(route('families.timeline.destroy', [$family, $entry]))->assertNotFound();
    $this->delete(route('families.timeline.destroy', [$other, $entry]))->assertNotFound();
    $this->get(route('families.events.index', $family))->assertNotFound();
    $this->post(route('families.events.store', $family), ['title' => 'x', 'type' => 'other', 'starts_at' => '2030-01-01'])->assertNotFound();
    $this->patch(route('families.events.update', [$family, $event]), ['title' => 'x', 'type' => 'other', 'starts_at' => '2030-01-01'])->assertNotFound();
    $this->delete(route('families.events.destroy', [$family, $event]))->assertNotFound();
    $this->delete(route('families.events.destroy', [$other, $event]))->assertNotFound();
    $this->post(route('families.events.convert', [$family, $event]))->assertNotFound();

    expect(TimelineEntry::query()->count())->toBe(1)->and(FamilyEvent::query()->count())->toBe(1);
});

// --- Events ----------------------------------------------------------------------------

test('events are scheduled in the person\'s own time zone and listed as upcoming or past', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00', 'Asia/Kuala_Lumpur'));
    [$family, $owner] = treeFamily();

    $this->actingAs($owner);
    $this->post(route('families.events.store', $family), [
        'title' => 'Kaamatan reunion', 'type' => 'reunion', 'starts_at' => '2027-05-30T10:00', 'ends_at' => '2027-05-30T18:00', 'location_text' => 'Kampung Sinakagon',
    ])->assertSessionHasNoErrors();
    $this->post(route('families.events.store', $family), ['title' => 'Memorial', 'type' => 'memorial', 'starts_at' => '2026-10-01', 'all_day' => true])->assertSessionHasNoErrors();

    $reunion = FamilyEvent::query()->where('title', 'Kaamatan reunion')->sole();

    // 10:00 in Kuala Lumpur is 02:00 UTC.
    expect($reunion->starts_at->toIso8601String())->toBe('2027-05-30T02:00:00+00:00');

    $this->get(route('families.events.index', $family))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('events/index')
        ->has('upcoming', 1)
        ->where('upcoming.0.title', 'Kaamatan reunion')
        ->where('upcoming.0.starts_local', '2027-05-30T10:00')
        ->has('past', 1)
        ->where('past.0.starts_local', '2026-10-01')
        ->where('past.0.happened', true));
});

test('an event cannot end before it starts', function () {
    [$family, $owner] = treeFamily();

    $this->actingAs($owner)
        ->post(route('families.events.store', $family), ['title' => 'Backwards', 'type' => 'other', 'starts_at' => '2030-01-02T10:00', 'ends_at' => '2030-01-01T10:00'])
        ->assertSessionHasErrors('ends_at');
});

test('a past event can be added to the timeline once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00', 'Asia/Kuala_Lumpur'));
    [$family, $owner] = treeFamily();
    $this->actingAs($owner);
    $this->post(route('families.events.store', $family), ['title' => 'Big reunion', 'type' => 'reunion', 'starts_at' => '2026-06-01', 'all_day' => true, 'location_text' => 'Ranau']);
    $this->post(route('families.events.store', $family), ['title' => 'Future', 'type' => 'reunion', 'starts_at' => '2027-06-01', 'all_day' => true]);
    $past = FamilyEvent::query()->where('title', 'Big reunion')->sole();
    $future = FamilyEvent::query()->where('title', 'Future')->sole();

    $this->post(route('families.events.convert', [$family, $past]))->assertRedirect();
    $this->post(route('families.events.convert', [$family, $past]))->assertStatus(422);
    $this->post(route('families.events.convert', [$family, $future]))->assertStatus(422);

    $entry = TimelineEntry::query()->sole();

    expect($entry->title)->toBe('Big reunion')
        ->and($entry->event->label())->toBe('1 Jun 2026')
        ->and($past->refresh()->timeline_id)->toBe($entry->id);
});

test('viewers can see events but not change them', function () {
    [$family, $owner] = treeFamily();
    $this->actingAs($owner)->post(route('families.events.store', $family), ['title' => 'Reunion', 'type' => 'reunion', 'starts_at' => '2030-01-01']);
    $event = FamilyEvent::query()->sole();

    $this->actingAs(memberWithRole($family, FamilyRole::Viewer));
    $this->get(route('families.events.index', $family))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));
    $this->post(route('families.events.store', $family), ['title' => 'x', 'type' => 'other', 'starts_at' => '2030-01-01'])->assertForbidden();
    $this->patch(route('families.events.update', [$family, $event]), ['title' => 'x', 'type' => 'other', 'starts_at' => '2030-01-01'])->assertForbidden();
    $this->delete(route('families.events.destroy', [$family, $event]))->assertForbidden();
});

// --- Dashboard -------------------------------------------------------------------------

test('the dashboard shows numbers, birthdays, anniversaries, events and recent people', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Kuala_Lumpur'));
    [$family, $owner] = treeFamily();

    $soon = person($family, 'Birthday Soon', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::day(1980, 11, 20)]);
    $spouse = person($family, 'Spouse', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::day(1982, 3, 1)]);
    person($family, 'Year Only', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::year(1990)]);
    person($family, 'Late Grandfather', ['living_status' => LivingStatus::Deceased, 'birth' => FuzzyDate::day(1920, 11, 15)]);
    partners($soon, $spouse, ['start' => ['year' => 2005, 'month' => 12, 'day' => 1]]);

    $this->actingAs($owner)->post(route('families.events.store', $family), ['title' => 'Reunion', 'type' => 'reunion', 'starts_at' => '2026-12-25', 'all_day' => true]);

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('overview.family.slug', $family->slug)
        ->where('overview.stats.members', 4)
        ->where('overview.stats.living', 3)
        ->where('overview.stats.deceased', 1)
        ->has('overview.birthdays', 1)
        ->where('overview.birthdays.0.person.name', 'Birthday Soon')
        ->where('overview.birthdays.0.days', 9)
        ->where('overview.birthdays.0.turning', 46)
        ->has('overview.anniversaries', 1)
        ->where('overview.anniversaries.0.years', 21)
        ->where('overview.anniversaries.0.days', 20)
        ->where('overview.events.0.title', 'Reunion')
        ->has('overview.recent', 4));

    // A viewer may not see living people's exact dates, so no birthdays or anniversaries.
    $this->actingAs(memberWithRole($family, FamilyRole::Viewer))
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('overview.birthdays', 0)
            ->has('overview.anniversaries', 0)
            ->where('overview.events.0.title', 'Reunion'));
});

test('a dashboard without a family has no overview', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('overview', null)->has('families', 0));
});

test('next occurrence handles year end and 29 February', function () {
    $today = CarbonImmutable::parse('2026-12-30', 'Asia/Kuala_Lumpur');

    expect(FamilyOverview::nextOccurrence(12, 30, $today)->toDateString())->toBe('2026-12-30')
        ->and(FamilyOverview::nextOccurrence(1, 2, $today)->toDateString())->toBe('2027-01-02')
        ->and(FamilyOverview::nextOccurrence(2, 29, $today)->toDateString())->toBe('2027-02-28')
        ->and(FamilyOverview::nextOccurrence(2, 29, CarbonImmutable::parse('2028-01-01'))->toDateString())->toBe('2028-02-29');
});

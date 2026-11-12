<?php

use App\Enums\FamilyRole;
use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Jobs\RecomputeGenerations;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Place;
use App\Models\User;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * Lidau ═ Rumind → Gimbang → Daniel (living) → Adam (living); Gimbang's other child Maria is private.
 *
 * @return array<string, FamilyMember>
 */
function smallTree(Family $family): array
{
    $ranau = Place::resolve($family, 'Kampung Sinakagon, Ranau');
    $kk = Place::resolve($family, 'Kota Kinabalu');

    $make = fn (string $name, Gender $gender, int $born, LivingStatus $status, ?Place $place, array $extra = []) => person($family, $name, [
        'gender' => $gender, 'birth' => FuzzyDate::year($born), 'living_status' => $status, 'birth_place_id' => $place?->id, ...$extra,
    ]);

    $people = [
        'lidau' => $make('Lidau bin Gunsalam', Gender::Male, 1898, LivingStatus::Deceased, $ranau, ['surname' => 'Gunsalam']),
        'rumind' => $make('Rumind binti Sadang', Gender::Female, 1903, LivingStatus::Deceased, $ranau),
        'gimbang' => $make('Gimbang Lidau', Gender::Male, 1925, LivingStatus::Deceased, $ranau, ['surname' => 'Lidau']),
        'daniel' => $make('Daniel Gimbang', Gender::Male, 1952, LivingStatus::Living, $kk),
        'maria' => $make('Maria Gimbang', Gender::Female, 1955, LivingStatus::Living, $kk, ['visibility' => Visibility::Private]),
        'adam' => $make('Adam Daniel', Gender::Male, 1980, LivingStatus::Living, $kk),
        'noah' => $make('Noah Maria', Gender::Male, 1982, LivingStatus::Living, $kk),
    ];

    partners($people['lidau'], $people['rumind']);
    parentOf($people['lidau'], $people['gimbang']);
    parentOf($people['rumind'], $people['gimbang']);
    parentOf($people['gimbang'], $people['daniel']);
    parentOf($people['gimbang'], $people['maria']);
    parentOf($people['daniel'], $people['adam']);
    parentOf($people['maria'], $people['noah']);

    $family->forceFill(['root_member_id' => $people['lidau']->id])->save();
    RecomputeGenerations::dispatchSync($family->id);

    return $people;
}

// --- Relationship finder ---------------------------------------------------------------

test('the finder names the relationship and shows the path between two people', function () {
    [$family, $owner] = treeFamily();
    $people = smallTree($family);

    $this->actingAs($owner)
        ->get(route('families.relationships.finder', ['family' => $family, 'from' => $people['adam']->id, 'to' => $people['lidau']->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('relationships/finder')
            ->where('from.name', 'Adam Daniel')
            ->where('to.name', 'Lidau bin Gunsalam')
            ->has('results', 1)
            ->where('results.0.label', 'great-grandfather')
            ->where('results.0.kind', 'ancestor')
            ->has('results.0.path', 4)
            ->where('results.0.path.1.person.name', 'Daniel Gimbang')
            ->where('results.0.path.1.via', 'parent')
            ->where('results.0.path.3.person.name', 'Lidau bin Gunsalam'));

    $this->get(route('families.relationships.finder', ['family' => $family, 'from' => $people['adam']->id, 'to' => $people['noah']->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.0.label', 'first cousin')
            ->where('results.0.path.2.person.name', 'Gimbang Lidau')
            ->where('results.0.path.3.person.name', 'Maria Gimbang'));

    // Nothing chosen yet: an empty form.
    $this->get(route('families.relationships.finder', $family))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('from', null)->where('to', null)->has('results', 0));
});

test('a private person on the path is not revealed, and cannot be chosen', function () {
    [$family] = treeFamily();
    $people = smallTree($family);
    $editor = memberWithRole($family, FamilyRole::Editor);

    // Adam and Noah are cousins through Maria, whom an editor may not see.
    $this->actingAs($editor)
        ->get(route('families.relationships.finder', ['family' => $family, 'from' => $people['adam']->id, 'to' => $people['noah']->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.0.label', 'first cousin')
            ->where('results.0.path.3.person.hidden', true)
            ->where('results.0.path.3.person.name', 'Private relative')
            ->where('results.0.path.3.person.gender', null)
            ->where('results.0.path.3.person.id', $people['maria']->id));

    // Asking about the private person directly: as if nobody was chosen.
    $this->get(route('families.relationships.finder', ['family' => $family, 'from' => $people['adam']->id, 'to' => $people['maria']->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('to', null)->has('results', 0));

    $this->get(route('families.relationships.finder', ['family' => $family, 'from' => 'not-an-id', 'to' => str_repeat('a', 36)]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('from', null)->where('to', null));
});

test('the finder starts from the viewer\'s own place in the tree; outsiders find nothing', function () {
    [$family] = treeFamily();
    $people = smallTree($family);
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $viewer->memberships()->sole()->forceFill(['linked_member_id' => $people['adam']->id])->save();

    $this->actingAs($viewer)
        ->get(route('families.relationships.finder', ['family' => $family, 'to' => $people['gimbang']->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('from.name', 'Adam Daniel')
            ->where('from.is_me', true)
            ->where('results.0.label', 'grandfather'));

    [, $outsider] = treeFamily();
    $this->actingAs($outsider)->get(route('families.relationships.finder', $family))->assertNotFound();

    // People of one family cannot be looked up through another family's address.
    [$other, $otherOwner] = treeFamily();
    $this->actingAs($otherOwner)
        ->get(route('families.relationships.finder', ['family' => $other, 'from' => $people['adam']->id, 'to' => $people['gimbang']->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('from', null)->where('to', null)->has('results', 0));
});

// --- Advanced search -------------------------------------------------------------------

test('people are found by surname, generation, years, place, living or not, and branch', function () {
    [$family, $owner] = treeFamily();
    $people = smallTree($family);

    $names = fn (array $query, ?User $as = null) => collect($this->actingAs($as ?? $owner)
        ->get(route('families.members.index', ['family' => $family, ...$query]))
        ->assertOk()
        ->inertiaProps('members.data'))->pluck('name')->sort()->values()->all();

    expect($names(['surname' => 'gunsalam']))->toBe(['Lidau bin Gunsalam'])
        ->and($names(['surname' => 'Lidau']))->toBe(['Gimbang Lidau', 'Lidau bin Gunsalam'])
        ->and($names(['generation' => 2]))->toBe(['Gimbang Lidau'])
        ->and($names(['generation' => 4]))->toBe(['Adam Daniel', 'Noah Maria'])
        ->and($names(['status' => 'deceased']))->toBe(['Gimbang Lidau', 'Lidau bin Gunsalam', 'Rumind binti Sadang'])
        ->and($names(['born_from' => 1950, 'born_to' => 1960]))->toBe(['Daniel Gimbang', 'Maria Gimbang'])
        ->and($names(['born_to' => 1903]))->toBe(['Lidau bin Gunsalam', 'Rumind binti Sadang'])
        ->and($names(['place' => 'ranau']))->toBe(['Gimbang Lidau', 'Lidau bin Gunsalam', 'Rumind binti Sadang'])
        ->and($names(['place' => 'Kota', 'born_from' => 1980]))->toBe(['Adam Daniel', 'Noah Maria'])
        ->and($names(['branch' => $people['daniel']->id]))->toBe(['Adam Daniel', 'Daniel Gimbang'])
        ->and($names(['branch' => $people['gimbang']->id, 'status' => 'living', 'search' => 'adam']))->toBe(['Adam Daniel'])
        // Nonsense input is ignored, never an error.
        ->and($names(['generation' => 'x', 'born_from' => '-5', 'branch' => 'nope', 'status' => 'maybe']))->toHaveCount(7)
        ->and($names(['place' => "%' OR 1=1 --"]))->toBe([]);

    $this->get(route('families.members.index', ['family' => $family, 'branch' => $people['daniel']->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.branch.name', 'Daniel Gimbang')
            ->where('generations', 4));
});

test('searching by year or place never reveals what a role may not see', function () {
    [$family] = treeFamily();
    $people = smallTree($family);
    $editor = memberWithRole($family, FamilyRole::Editor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $names = fn (User $as, array $query) => collect($this->actingAs($as)
        ->get(route('families.members.index', ['family' => $family, ...$query]))
        ->inertiaProps('members.data'))->pluck('name')->sort()->values()->all();

    // An editor sees living people's dates, but not the private person.
    expect($names($editor, ['born_from' => 1950, 'born_to' => 1960]))->toBe(['Daniel Gimbang'])
        ->and($names($editor, ['branch' => $people['maria']->id]))->toHaveCount(6)   // not a branch they can choose
        // A viewer does not see living people's birth years or places: those filters skip them.
        ->and($names($viewer, ['born_from' => 1950, 'born_to' => 1990]))->toBe([])
        ->and($names($viewer, ['place' => 'Kota Kinabalu']))->toBe([])
        ->and($names($viewer, ['born_to' => 1930]))->toBe(['Gimbang Lidau', 'Lidau bin Gunsalam', 'Rumind binti Sadang'])
        ->and($names($viewer, ['place' => 'Ranau']))->toHaveCount(3)
        // Other filters still work for them.
        ->and($names($viewer, ['generation' => 4]))->toBe(['Adam Daniel', 'Noah Maria'])
        ->and($names($viewer, ['status' => 'living']))->toBe(['Adam Daniel', 'Daniel Gimbang', 'Noah Maria']);

    // Their own record is theirs to find.
    $adam = memberWithRole($family, FamilyRole::Viewer);
    $adam->memberships()->sole()->forceFill(['linked_member_id' => $people['adam']->id])->save();
    expect($names($adam, ['born_from' => 1980, 'born_to' => 1980]))->toBe(['Adam Daniel']);
});

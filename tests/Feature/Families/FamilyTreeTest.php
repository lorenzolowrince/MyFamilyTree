<?php

use App\Enums\FamilyRole;
use App\Enums\LivingStatus;
use App\Enums\MembershipStatus;
use App\Enums\Visibility;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\User;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Support\FuzzyDate\FuzzyDate;
use Database\Factories\FamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

// --- Creating a family -----------------------------------------------------------------

test('a verified user can create a family and becomes its owner and first person', function () {
    $user = User::factory()->create(['name' => 'Lorenzo Lidau']);

    $response = $this->actingAs($user)->post(route('families.store'), [
        'name' => 'Keluarga Lidau',
        'description' => 'From Ranau',
        'add_myself' => true,
    ]);

    $family = Family::query()->sole();
    $me = FamilyMember::query()->sole();

    $response->assertRedirect(route('families.tree', $family));

    expect($family->slug)->toBe('keluarga-lidau')
        ->and($family->owner_id)->toBe($user->id)
        ->and($family->root_member_id)->toBe($me->id)
        ->and($me->full_name)->toBe('Lorenzo Lidau')
        ->and($me->living_status)->toBe(LivingStatus::Living)
        ->and($user->memberships()->sole()->role)->toBe(FamilyRole::Owner)
        ->and($user->memberships()->sole()->linked_member_id)->toBe($me->id);
});

test('two families with the same name get different addresses', function () {
    // Owning the first family makes two-factor compulsory before anything else.
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->post(route('families.store'), ['name' => 'Keluarga Lidau']);
    $this->actingAs($user)->post(route('families.store'), ['name' => 'Keluarga Lidau']);

    expect(Family::query()->pluck('slug')->unique())->toHaveCount(2);
});

test('an unverified user cannot create a family', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('families.store'), ['name' => 'Keluarga Lidau'])
        ->assertRedirect(route('verification.notice'));

    expect(Family::query()->count())->toBe(0);
});

test('a family name is required', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('families.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('the dashboard and family list show only the families the user belongs to', function () {
    [$mine, $owner] = treeFamily();
    treeFamily();
    person($mine, 'Someone');

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('dashboard')
        ->has('families', 1)
        ->where('families.0.slug', $mine->slug)
        ->where('families.0.members', 1)
        ->where('families.0.role', 'Owner'));

    $this->get(route('families.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('families/index')
        ->has('families', 1));
});

// --- Who gets in -----------------------------------------------------------------------

test('guests are sent to sign in', function () {
    [$family] = treeFamily();

    $this->get(route('families.tree', $family))->assertRedirect(route('login'));
});

test('every family route is "not found" for someone outside the family', function () {
    [$family] = treeFamily();
    $ahmad = person($family, 'Ahmad');
    $siti = person($family, 'Siti');
    $relationship = parentOf($ahmad, $siti);

    // An owner of a different family, fully set up, with their own person.
    [$other, $outsider] = treeFamily();
    $stranger = person($other, 'Stranger');

    $this->actingAs($outsider);

    $this->get(route('families.show', $family))->assertNotFound();
    $this->get(route('families.tree', $family))->assertNotFound();
    $this->get(route('families.edit', $family))->assertNotFound();
    $this->patch(route('families.update', $family), ['name' => 'Taken over'])->assertNotFound();
    $this->get(route('families.cover.show', $family))->assertNotFound();
    $this->post(route('families.cover.store', $family))->assertNotFound();
    $this->delete(route('families.cover.destroy', $family))->assertNotFound();
    $this->get(route('families.members.index', $family))->assertNotFound();
    $this->getJson(route('families.members.lookup', [$family, 'search' => 'Ahmad']))->assertNotFound();
    $this->getJson(route('families.members.duplicates', [$family, 'name' => 'Ahmad']))->assertNotFound();
    $this->post(route('families.members.store', $family), ['full_name' => 'Intruder'])->assertNotFound();
    $this->get(route('families.members.show', [$family, $ahmad]))->assertNotFound();
    $this->patch(route('families.members.update', [$family, $ahmad]), ['full_name' => 'Renamed', 'version' => 1])->assertNotFound();
    $this->delete(route('families.members.destroy', [$family, $ahmad]))->assertNotFound();
    $this->post(route('families.members.root', [$family, $ahmad]))->assertNotFound();
    $this->post(route('families.members.relatives.store', [$family, $ahmad]), ['kind' => 'child', 'member' => ['full_name' => 'Intruder']])->assertNotFound();
    $this->patch(route('families.relationships.update', [$family, $relationship]), ['subtype' => 'adoptive'])->assertNotFound();
    $this->delete(route('families.relationships.destroy', [$family, $relationship]))->assertNotFound();

    // Their own family's address with the other family's records is also "not found".
    $this->get(route('families.members.show', [$other, $ahmad]))->assertNotFound();
    $this->delete(route('families.relationships.destroy', [$other, $relationship]))->assertNotFound();

    expect($family->refresh()->name)->not->toBe('Taken over')
        ->and($ahmad->refresh()->full_name)->toBe('Ahmad')
        ->and(FamilyMember::query()->where('full_name', 'Intruder')->exists())->toBeFalse()
        ->and(FamilyRelationship::query()->count())->toBe(1)
        ->and($stranger->refresh()->family_id)->toBe($other->id);
});

test('every family route requires sign-in, a verified email and the two-factor check', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => Str::startsWith((string) $route->getName(), 'families.'));

    expect($routes->count())->toBeGreaterThan(15);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('auth', 'verified', EnsureTwoFactorEnabled::class);
    }
});

test('an owner without two-factor is sent to turn it on, unless the requirement is off', function () {
    $owner = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($owner)->get(route('families.tree', $family))->assertRedirect(route('security.edit'));

    config(['families.require_two_factor_for_admins' => false]);

    $this->actingAs($owner)->get(route('families.tree', $family))->assertOk();
});

test('a suspended member of the family is treated as an outsider', function () {
    [$family] = treeFamily();
    $user = User::factory()->create();
    FamilyFactory::addMember($family, $user, FamilyRole::Editor, MembershipStatus::Suspended);

    $this->actingAs($user)->get(route('families.tree', $family))->assertNotFound();
});

// --- What each role may do -------------------------------------------------------------

// How each role's changes are handled: applied at once, sent for approval, or refused.
dataset('roles', [
    'owner' => [FamilyRole::Owner, 'direct', 'direct', true],
    'admin' => [FamilyRole::Admin, 'direct', 'direct', true],
    'editor' => [FamilyRole::Editor, 'direct', 'propose', false],
    'contributor' => [FamilyRole::Contributor, 'propose', 'deny', false],
    'viewer' => [FamilyRole::Viewer, 'deny', 'deny', false],
]);

test('roles: view, add, edit, link, delete and settings', function (FamilyRole $role, string $edit, string $delete, bool $canManageFamily) {
    [$family] = treeFamily();
    $ahmad = person($family, 'Ahmad', ['living_status' => LivingStatus::Deceased]);
    $siti = person($family, 'Siti', ['living_status' => LivingStatus::Deceased]);
    $relationship = parentOf($ahmad, $siti);

    $this->actingAs(memberWithRole($family, $role));

    // Everyone in the family can look.
    $this->get(route('families.tree', $family))->assertOk();
    $this->get(route('families.members.index', $family))->assertOk();
    $this->get(route('families.members.show', [$family, $ahmad]))->assertOk();

    // Allowed (directly or as a proposal) redirects; refused is 403.
    $expect = fn ($response, bool $allowed) => $allowed ? $response->assertRedirect() : $response->assertForbidden();

    $expect($this->post(route('families.members.store', $family), ['full_name' => 'New Person']), $edit !== 'deny');
    $expect($this->patch(route('families.members.update', [$family, $ahmad]), ['full_name' => 'Ahmad bin Ali', 'version' => 1]), $edit !== 'deny');
    $expect($this->post(route('families.members.relatives.store', [$family, $ahmad]), ['kind' => 'child', 'member' => ['full_name' => 'New Child']]), $edit !== 'deny');
    // Changing or removing an existing link has no approval path.
    $expect($this->patch(route('families.relationships.update', [$family, $relationship]), ['subtype' => 'adoptive']), $edit === 'direct');
    $expect($this->patch(route('families.update', $family), ['name' => 'Renamed Family']), $canManageFamily);
    $expect($this->post(route('families.members.root', [$family, $siti])), $canManageFamily);
    $expect($this->delete(route('families.relationships.destroy', [$family, $relationship])), $edit === 'direct');
    $expect($this->delete(route('families.members.destroy', [$family, $siti])), $delete !== 'deny');

    // Only direct changes touch the tree.
    expect(FamilyMember::query()->where('full_name', 'New Person')->exists())->toBe($edit === 'direct')
        ->and(FamilyMember::query()->where('full_name', 'New Child')->exists())->toBe($edit === 'direct')
        ->and($ahmad->refresh()->full_name)->toBe($edit === 'direct' ? 'Ahmad bin Ali' : 'Ahmad')
        ->and($family->refresh()->name === 'Renamed Family')->toBe($canManageFamily)
        ->and(FamilyMember::query()->whereKey($siti->id)->exists())->toBe($delete !== 'direct');

    // Proposals wait in the queue instead.
    $proposals = ChangeRequest::query()->where('status', 'pending')->count();

    expect($proposals)->toBe(($edit === 'propose' ? 3 : 0) + ($delete === 'propose' ? 1 : 0));
})->with('roles');

test('a contributor can edit their own linked profile, but not research notes or visibility', function () {
    [$family] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $me = person($family, 'Me', ['living_status' => LivingStatus::Living, 'notes' => 'Editors only']);
    $someoneElse = person($family, 'Someone Else');
    $contributor->memberships()->sole()->forceFill(['linked_member_id' => $me->id])->save();

    $this->actingAs($contributor);

    $this->patch(route('families.members.update', [$family, $me]), [
        'full_name' => 'My Real Name', 'version' => 1, 'notes' => 'Overwritten', 'visibility' => 'private',
    ])->assertRedirect();

    // Someone else's profile: a proposal, not a change.
    $this->patch(route('families.members.update', [$family, $someoneElse]), ['full_name' => 'Hacked', 'version' => 1])
        ->assertRedirect();

    expect(ChangeRequest::query()->where('target_id', $someoneElse->id)->where('status', 'pending')->count())->toBe(1);

    expect($me->refresh()->full_name)->toBe('My Real Name')
        ->and($me->notes)->toBe('Editors only')
        ->and($me->visibility)->toBe(Visibility::Family)
        ->and($someoneElse->refresh()->full_name)->not->toBe('Hacked');
});

// --- Adding relatives through the forms -------------------------------------------------

test('a four-generation family with two marriages, an adoption and half-siblings can be entered through the forms', function () {
    $owner = User::factory()->withTwoFactor()->create(['name' => 'Daud']);
    $this->actingAs($owner)->post(route('families.store'), ['name' => 'Keluarga Daud', 'add_myself' => true]);

    $family = Family::query()->sole();
    $daud = FamilyMember::query()->sole();

    $add = function (FamilyMember $anchor, string $kind, array $data) use ($family): FamilyMember {
        $this->post(route('families.members.relatives.store', [$family, $anchor]), ['kind' => $kind, ...$data])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return FamilyMember::query()->where('full_name', $data['member']['full_name'])->sole();
    };

    // Generation 1: Daud's parents.
    $father = $add($daud, 'parent', ['member' => ['full_name' => 'Haji Ali', 'gender' => 'male', 'birth' => ['year' => 1920, 'qualifier' => 'about']]]);
    $mother = $add($daud, 'parent', ['member' => ['full_name' => 'Hajah Minah', 'gender' => 'female']]);
    $add($father, 'partner', ['existing_member_id' => $mother->id, 'member' => ['full_name' => 'Hajah Minah'], 'subtype' => 'marriage']);

    // Generation 2: Daud, a full sibling, two marriages.
    $sibling = $add($daud, 'sibling', ['member' => ['full_name' => 'Salmah']]);
    $firstWife = $add($daud, 'partner', ['member' => ['full_name' => 'Aminah', 'gender' => 'female'], 'subtype' => 'marriage', 'status' => 'divorced', 'start' => ['year' => 1970]]);
    $secondWife = $add($daud, 'partner', ['member' => ['full_name' => 'Zainab', 'gender' => 'female'], 'subtype' => 'marriage', 'status' => 'current']);

    // Generation 3: a child from each marriage (half-siblings) and an adopted child.
    $son = $add($daud, 'child', ['member' => ['full_name' => 'Karim', 'gender' => 'male'], 'other_parent_id' => $firstWife->id]);
    $daughter = $add($daud, 'child', ['member' => ['full_name' => 'Laila', 'gender' => 'female'], 'other_parent_id' => $secondWife->id]);
    $adopted = $add($daud, 'child', ['member' => ['full_name' => 'Musa'], 'subtype' => 'adoptive', 'other_parent_id' => $secondWife->id]);

    // Generation 4.
    $grandchild = $add($son, 'child', ['member' => ['full_name' => 'Nora']]);

    $graph = app(FamilyGraphLoader::class)->for($family);

    expect($graph->parentIds($daud->id))->toEqualCanonicalizing([$father->id, $mother->id])
        ->and($graph->parentIds($sibling->id))->toEqualCanonicalizing([$father->id, $mother->id])
        ->and($graph->partnerIds($daud->id))->toEqualCanonicalizing([$firstWife->id, $secondWife->id])
        ->and($graph->parentIds($son->id))->toEqualCanonicalizing([$daud->id, $firstWife->id])
        ->and($graph->parentIds($daughter->id))->toEqualCanonicalizing([$daud->id, $secondWife->id])
        ->and($graph->biologicalParentIds($adopted->id))->toBe([])
        ->and($graph->siblingIds($son->id))->toEqualCanonicalizing([$daughter->id, $adopted->id])
        ->and($grandchild->refresh()->generation)->toBe(4)
        ->and($father->refresh()->generation)->toBe(1)
        ->and($firstWife->refresh()->generation)->toBe(2);

    // The tree page draws Daud with both wives, each with her own children.
    $this->get(route('families.tree', [$family, 'view' => 'hourglass']))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('families/tree')
        ->where('slice.focus', $daud->id)
        ->has('slice.descendants.unions', 2)
        ->where('slice.descendants.unions.0.partner', $firstWife->id)
        ->where('slice.descendants.unions.0.relationship.status', 'divorced')
        ->where('slice.descendants.unions.0.children.0.person', $son->id)
        ->where('slice.descendants.unions.0.children.0.unions.0.children.0.person', $grandchild->id)
        ->has('slice.descendants.unions.1.children', 2)
        ->has('slice.ancestors.parents', 2)
        ->where('slice.ancestors.parents.0.person', $father->id)
        ->where("people.{$daud->id}.is_me", true)
        ->where("people.{$father->id}.name", 'Haji Ali')
        ->where("people.{$adopted->id}.name", 'Musa'));

    // The profile lists half- and adoptive siblings as such.
    $this->get(route('families.members.show', [$family, $son]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('members/show')
        ->has('relatives.parents', 2)
        ->has('relatives.siblings', 2)
        ->where('relatives.siblings', fn ($siblings) => collect($siblings)->pluck('kind', 'person.name')->all() === ['Laila' => 'half', 'Musa' => 'by_family'])
        ->has('relatives.children', 1));
});

test('an invalid structure is rejected with a clear message and nothing is saved', function () {
    [$family, $owner] = treeFamily();
    $grandfather = person($family, 'Ahmad');
    $father = person($family, 'Bakar');
    $child = person($family, 'Siti');
    parentOf($grandfather, $father);
    parentOf($father, $child);

    $this->actingAs($owner)
        ->from(route('families.members.show', [$family, $grandfather]))
        ->post(route('families.members.relatives.store', [$family, $grandfather]), ['kind' => 'parent', 'existing_member_id' => $child->id])
        ->assertRedirect(route('families.members.show', [$family, $grandfather]))
        ->assertSessionHasErrors(['relationship' => 'Siti cannot be a parent of Ahmad, because Ahmad is already an ancestor of Siti.']);

    expect(FamilyRelationship::query()->count())->toBe(2);
});

test('a relative cannot be chosen from another family', function () {
    [$family, $owner] = treeFamily();
    [$other] = treeFamily();
    $ahmad = person($family, 'Ahmad');
    $stranger = person($other, 'Stranger');

    $this->actingAs($owner)
        ->post(route('families.members.relatives.store', [$family, $ahmad]), ['kind' => 'child', 'existing_member_id' => $stranger->id])
        ->assertSessionHasErrors('existing_member_id');

    expect(FamilyRelationship::query()->count())->toBe(0);
});

test('the duplicate check finds people with a similar name', function () {
    [$family, $owner] = treeFamily();
    person($family, 'Jamilah binti Lidau', ['birth' => FuzzyDate::year(1950)]);
    person($family, 'Completely Different');

    $this->actingAs($owner)
        ->getJson(route('families.members.duplicates', [$family, 'name' => 'Jamila binti Lidow', 'birth_year' => 1951]))
        ->assertOk()
        ->assertJsonCount(1, 'members')
        ->assertJsonPath('members.0.name', 'Jamilah binti Lidau');
});

// --- Editing ---------------------------------------------------------------------------

test('a person can be edited with fuzzy dates and places', function () {
    [$family, $owner] = treeFamily();
    $ahmad = person($family, 'Ahmad');

    $this->actingAs($owner)->patch(route('families.members.update', [$family, $ahmad]), [
        'version' => 1,
        'full_name' => 'Ahmad bin Ali',
        'living_status' => 'deceased',
        'birth' => ['year' => 1920, 'qualifier' => 'about'],
        'birth_place' => 'Kampung Sinakagon, Ranau',
        'death' => ['year' => 1988, 'month' => 3, 'day' => 12],
        'death_place' => 'kampung sinakagon, ranau',
    ])->assertSessionHasNoErrors();

    $ahmad->refresh();

    expect($ahmad->birth->label())->toBe('about 1920')
        ->and($ahmad->death->label())->toBe('12 Mar 1988')
        ->and($ahmad->version)->toBe(2)
        ->and($ahmad->birth_place_id)->toBe($ahmad->death_place_id)   // one place, whatever the capitals
        ->and($family->places()->count())->toBe(1);
});

test('saving over someone else\'s newer changes is refused', function () {
    [$family, $owner] = treeFamily();
    $ahmad = person($family, 'Ahmad');
    $ahmad->forceFill(['version' => 2])->save();

    $this->actingAs($owner)
        ->patch(route('families.members.update', [$family, $ahmad]), ['full_name' => 'Stale Edit', 'version' => 1])
        ->assertSessionHasErrors('version');

    expect($ahmad->refresh()->full_name)->toBe('Ahmad');
});

test('dates are validated', function (array $input, string $error) {
    [$family, $owner] = treeFamily();
    $ahmad = person($family, 'Ahmad');

    $this->actingAs($owner)
        ->patch(route('families.members.update', [$family, $ahmad]), ['full_name' => 'Ahmad', 'version' => 1, ...$input])
        ->assertSessionHasErrors($error);
})->with([
    'death before birth' => [['living_status' => 'deceased', 'birth' => ['year' => 1950], 'death' => ['year' => 1940]], 'death.year'],
    '30 February' => [['birth' => ['year' => 1950, 'month' => 2, 'day' => 30]], 'birth.day'],
    'born in the future' => [['birth' => ['year' => 2999]], 'birth.year'],
    'month without year' => [['birth' => ['month' => 5]], 'birth.year'],
    'range without an end' => [['birth' => ['year' => 1890, 'qualifier' => 'between']], 'birth.year_to'],
]);

test('naming an unknown parent turns the placeholder into a real person', function () {
    [$family, $owner] = treeFamily();
    $placeholder = person($family, 'Unknown parent', ['is_placeholder' => true]);

    $this->actingAs($owner)
        ->patch(route('families.members.update', [$family, $placeholder]), ['full_name' => 'Haji Ali', 'version' => 1])
        ->assertSessionHasNoErrors();

    expect($placeholder->refresh()->is_placeholder)->toBeFalse();
});

// --- Privacy ---------------------------------------------------------------------------

test('living people: viewers see the name but not dates; editors see everything', function () {
    [$family] = treeFamily();
    $living = person($family, 'Living Aunt', [
        'living_status' => LivingStatus::Living,
        'birth' => FuzzyDate::day(1975, 6, 1),
        'occupation' => 'Teacher',
        'notes' => 'Research note',
    ]);
    $deceased = person($family, 'Late Grandfather', [
        'living_status' => LivingStatus::Deceased,
        'birth' => FuzzyDate::year(1900),
    ]);
    parentOf($deceased, $living);

    $this->actingAs(memberWithRole($family, FamilyRole::Viewer))
        ->get(route('families.members.show', [$family, $living]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('member.name', 'Living Aunt')
            ->where('member.sensitive_hidden', true)
            ->where('member.birth', null)
            ->where('member.lifespan', null)
            ->where('member.occupation', null)
            ->where('member.notes', null)
            ->where('relatives.parents.0.person.lifespan', '1900 – ?'));

    $this->get(route('families.tree', [$family, 'focus' => $living->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("people.{$living->id}.birth_year", null)
            ->where("people.{$deceased->id}.birth_year", 1900));

    $this->actingAs(memberWithRole($family, FamilyRole::Editor))
        ->get(route('families.members.show', [$family, $living]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('member.sensitive_hidden', false)
            ->where('member.birth.year', 1975)
            ->where('member.occupation', 'Teacher')
            ->where('member.notes', 'Research note'));
});

test('restricted and private people are invisible to roles that may not see them', function () {
    [$family] = treeFamily();
    $father = person($family, 'Father', ['living_status' => LivingStatus::Deceased]);
    $secret = person($family, 'Secret Child', ['visibility' => Visibility::Private]);
    $restricted = person($family, 'Restricted Child', ['visibility' => Visibility::Restricted]);
    parentOf($father, $secret);
    parentOf($father, $restricted);

    // A viewer: both are hidden everywhere.
    $this->actingAs(memberWithRole($family, FamilyRole::Viewer));

    $this->get(route('families.members.show', [$family, $secret]))->assertNotFound();
    $this->get(route('families.members.show', [$family, $restricted]))->assertNotFound();
    $this->get(route('families.members.index', $family))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('members.data', 1)->where('members.data.0.name', 'Father'));
    $this->getJson(route('families.members.lookup', [$family, 'search' => 'Child']))->assertJsonCount(0, 'members');
    $this->getJson(route('families.members.duplicates', [$family, 'name' => 'Secret Child']))->assertJsonCount(0, 'members');

    // The tree keeps its shape but reveals nothing about them.
    $this->get(route('families.tree', [$family, 'focus' => $father->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('slice.descendants.unions.0.children', 2)
            ->where("people.{$secret->id}.hidden", true)
            ->where("people.{$secret->id}.name", 'Private relative')
            ->where("people.{$restricted->id}.hidden", true));

    // Asking to centre the tree on a hidden person falls back to someone visible.
    $this->get(route('families.tree', [$family, 'focus' => $secret->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('slice.focus', $father->id));

    // An editor sees restricted but not private; an admin sees both.
    $this->actingAs(memberWithRole($family, FamilyRole::Editor));
    $this->get(route('families.members.show', [$family, $restricted]))->assertOk();
    $this->get(route('families.members.show', [$family, $secret]))->assertNotFound();
    $this->post(route('families.members.relatives.store', [$family, $father]), ['kind' => 'child', 'existing_member_id' => $secret->id])->assertNotFound();

    $this->actingAs(memberWithRole($family, FamilyRole::Admin));
    $this->get(route('families.members.show', [$family, $secret]))->assertOk();
});

test('a person can always see their own linked profile', function () {
    [$family] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $me = person($family, 'Me', [
        'living_status' => LivingStatus::Living,
        'visibility' => Visibility::Private,
        'birth' => FuzzyDate::year(1990),
    ]);
    $viewer->memberships()->sole()->forceFill(['linked_member_id' => $me->id])->save();

    $this->actingAs($viewer)
        ->get(route('families.members.show', [$family, $me]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('member.birth.year', 1990)
            ->where('linkedToMe', true));
});

// --- Search ----------------------------------------------------------------------------

test('member search matches partial names, close spellings and ignores accents', function () {
    [$family, $owner] = treeFamily();
    person($family, 'Jamilah binti Lidau');
    person($family, 'José Fernández');
    person($family, 'Someone Else');

    $names = fn (string $search): array => $this->actingAs($owner)
        ->getJson(route('families.members.lookup', [$family, 'search' => $search]))
        ->assertOk()
        ->json('members.*.name');

    expect($names('lida'))->toBe(['Jamilah binti Lidau'])
        ->and($names('Jamila Lidow'))->toBe(['Jamilah binti Lidau'])
        ->and($names('jose fernandez'))->toBe(['José Fernández'])
        ->and($names("%' OR 1=1 --"))->toBe([])
        ->and($names('%'))->toBe([]);
});

// --- Cover image -----------------------------------------------------------------------

test('an administrator can upload, replace and remove the cover image; members can view it', function () {
    Storage::fake('local');
    [$family, $owner] = treeFamily();

    $this->actingAs($owner)
        ->post(route('families.cover.store', $family), ['cover' => UploadedFile::fake()->image('../../evil.php.jpg', 800, 400)])
        ->assertSessionHasNoErrors();

    $path = $family->refresh()->settings['cover_path'];

    expect($path)->toStartWith("covers/{$family->id}/")
        ->and($path)->not->toContain('evil')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $this->actingAs(memberWithRole($family, FamilyRole::Viewer))
        ->get(route('families.cover.show', $family))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $this->post(route('families.cover.store', $family), ['cover' => UploadedFile::fake()->image('mine.jpg', 800, 400)])->assertForbidden();

    $this->actingAs($owner)->delete(route('families.cover.destroy', $family));

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($family->refresh()->settings)->not->toHaveKey('cover_path');
});

test('only real images are accepted as a cover', function () {
    Storage::fake('local');
    [$family, $owner] = treeFamily();

    $this->actingAs($owner)
        ->post(route('families.cover.store', $family), ['cover' => UploadedFile::fake()->create('script.php', 10, 'application/x-php')])
        ->assertSessionHasErrors('cover');

    $this->post(route('families.cover.store', $family), ['cover' => UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;')])
        ->assertSessionHasErrors('cover');

    expect($family->refresh()->settings)->not->toHaveKey('cover_path');
});

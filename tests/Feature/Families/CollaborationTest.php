<?php

use App\Enums\FamilyRole;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Models\ChangeRequest;
use App\Models\FamilyInvitation;
use App\Models\FamilyMember;
use App\Models\FamilyMembership;
use App\Models\FamilyRelationship;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Notifications\ChangeRequestDecided;
use App\Notifications\ChangeRequestSubmitted;
use App\Notifications\FamilyInvitationSent;
use App\Notifications\MemberJoinedFamily;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/** Send an invitation and return [the invitation, the token from the emailed link]. */
function invite($test, $family, User $inviter, string $email, string $role = 'viewer', array $extra = []): array
{
    Notification::fake();

    $test->actingAs($inviter)
        ->post(route('families.people.invite', $family), ['email' => $email, 'role' => $role, ...$extra])
        ->assertSessionHasNoErrors();

    $token = null;

    Notification::assertSentOnDemand(FamilyInvitationSent::class, function (FamilyInvitationSent $mail, array $channels, AnonymousNotifiable $to) use (&$token, $email): bool {
        $token = Str::afterLast($mail->url, '/');

        return $to->routes['mail'] === $email;
    });

    // By token, not by "latest": two invitations to one address can share a timestamp.
    return [FamilyInvitation::query()->where('token_hash', hash('sha256', (string) $token))->firstOrFail(), $token];
}

// --- Invitations -----------------------------------------------------------------------

test('an owner invites by email; only a hash of the token is stored', function () {
    [$family, $owner] = treeFamily();

    [$invitation, $token] = invite($this, $family, $owner, 'cousin@example.com', 'contributor', ['message' => 'Join us!']);

    expect($token)->toHaveLength(64)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->role)->toBe(FamilyRole::Contributor)
        ->and($invitation->expires_at->diffInDays(now(), true))->toBeGreaterThan(6.9)
        ->and(DB::table('audit_logs')->where('action', 'invitation.sent')->count())->toBe(1);
});

test('the invited person accepts and becomes a member with the invited role', function () {
    [$family, $owner] = treeFamily();
    $me = person($family, 'Cousin In Tree');
    [$invitation, $token] = invite($this, $family, $owner, 'cousin@example.com', 'contributor', ['member_id' => $me->id]);

    $cousin = User::factory()->create(['email' => 'cousin@example.com']);

    $this->actingAs($cousin)->get(route('invitations.show', $token))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('invitations/show')
        ->where('invitation.family', $family->name)
        ->where('invitation.role', 'Contributor')
        ->where('invitation.email_hint', 'c•••••@example.com')
        ->where('viewer.matches', true));

    $this->post(route('invitations.accept', $token))->assertRedirect(route('families.tree', $family));

    $membership = FamilyMembership::query()->where('user_id', $cousin->id)->sole();

    expect($membership->role)->toBe(FamilyRole::Contributor)
        ->and($membership->linked_member_id)->toBe($me->id)
        ->and($invitation->refresh()->accepted_by)->toBe($cousin->id);

    Notification::assertSentTo($owner, MemberJoinedFamily::class);

    // The link works once.
    $this->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');
    $this->get(route('families.tree', $family))->assertOk();
});

test('a forwarded link does not let a different account in', function () {
    [$family, $owner] = treeFamily();
    [, $token] = invite($this, $family, $owner, 'cousin@example.com');

    $stranger = User::factory()->create(['email' => 'stranger@example.com']);

    $this->actingAs($stranger)->get(route('invitations.show', $token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viewer.matches', false));

    $this->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');

    expect(FamilyMembership::query()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('an unverified account cannot accept, and guests are sent to sign in', function () {
    [$family, $owner] = treeFamily();
    [, $token] = invite($this, $family, $owner, 'cousin@example.com');

    $this->post(route('logout'));
    $this->post(route('invitations.accept', $token))->assertRedirect(route('login'));
    $this->get(route('invitations.show', $token))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('viewer', null));

    $this->actingAs(User::factory()->unverified()->create(['email' => 'cousin@example.com']))
        ->post(route('invitations.accept', $token))
        ->assertRedirect(route('verification.notice'));

    expect(FamilyMembership::query()->where('family_id', $family->id)->count())->toBe(1);
});

test('expired, cancelled and unknown invitations cannot be used', function () {
    [$family, $owner] = treeFamily();
    $cousin = User::factory()->create(['email' => 'cousin@example.com']);

    [$expired, $expiredToken] = invite($this, $family, $owner, 'cousin@example.com');
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->actingAs($cousin)->post(route('invitations.accept', $expiredToken))->assertSessionHasErrors('invitation');
    $this->get(route('invitations.show', $expiredToken))->assertInertia(fn (AssertableInertia $page) => $page->where('invitation.state', 'expired'));

    // A new invitation to the same address replaces the old one.
    [$second, $secondToken] = invite($this, $family, $owner, 'cousin@example.com');
    expect($expired->refresh()->revoked_at)->not->toBeNull();

    $this->actingAs($owner)->delete(route('families.people.invitations.destroy', [$family, $second]))->assertRedirect();
    $this->actingAs($cousin)->post(route('invitations.accept', $secondToken))->assertSessionHasErrors('invitation');

    $this->get(route('invitations.show', str_repeat('a', 64)))->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
    $this->post(route('invitations.accept', str_repeat('a', 64)))->assertNotFound();
    $this->get(route('invitations.show', 'short'))->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));

    expect(FamilyMembership::query()->where('user_id', $cousin->id)->exists())->toBeFalse();
});

test('who may invite, and at which role', function () {
    [$family, $owner] = treeFamily();
    $admin = memberWithRole($family, FamilyRole::Admin);
    $editor = memberWithRole($family, FamilyRole::Editor);
    Notification::fake();

    $send = fn (User $as, string $role) => $this->actingAs($as)->post(route('families.people.invite', $family), ['email' => Str::random(8).'@example.com', 'role' => $role]);

    $send($owner, 'admin')->assertSessionHasNoErrors()->assertRedirect();
    expect(FamilyInvitation::query()->sole()->email)->toBe(Str::lower(FamilyInvitation::query()->sole()->email));   // stored in lower case
    $send($owner, 'owner')->assertSessionHasErrors('role');          // ownership is only transferred
    $send($admin, 'editor')->assertSessionHasNoErrors()->assertRedirect();
    $send($admin, 'admin')->assertForbidden();                       // not a role below their own
    $send($editor, 'viewer')->assertForbidden();

    // Someone who is already in the family cannot be invited again.
    $this->actingAs($owner)->post(route('families.people.invite', $family), ['email' => $editor->email, 'role' => 'viewer'])
        ->assertSessionHasErrors('email');

    // Outsiders: not found.
    [, $outsider] = treeFamily();
    $this->actingAs($outsider)->post(route('families.people.invite', $family), ['email' => 'x@example.com', 'role' => 'viewer'])->assertNotFound();
    $this->get(route('families.people.index', $family))->assertNotFound();
});

// --- Roles and membership --------------------------------------------------------------

test('the people page shows email addresses only to those who manage access', function () {
    [$family, $owner] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $this->actingAs($owner)->get(route('families.people.index', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('people/index')
        ->has('people', 2)
        ->where('people.0.email', $owner->email)
        ->where('can.invite', true));

    $this->actingAs($viewer)->get(route('families.people.index', $family))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('people.0.email', null)
        ->has('invitations', 0)
        ->where('can.invite', false)
        ->where('can.leave', true));
});

test('roles can only be changed for people below your own role, and only to roles below your own', function () {
    [$family, $owner] = treeFamily();
    $admin = memberWithRole($family, FamilyRole::Admin);
    $otherAdmin = memberWithRole($family, FamilyRole::Admin);
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $membership = fn (User $user) => $user->memberships()->where('family_id', $family->id)->sole();
    $change = fn (User $as, User $target, string $role) => $this->actingAs($as)->patch(route('families.people.role', [$family, $membership($target)]), ['role' => $role]);

    $change($admin, $viewer, 'editor')->assertRedirect();
    expect($membership($viewer)->role)->toBe(FamilyRole::Editor);

    $change($admin, $viewer, 'admin')->assertForbidden();        // cannot grant own level
    $change($admin, $otherAdmin, 'viewer')->assertForbidden();   // cannot touch an equal
    $change($admin, $owner, 'viewer')->assertForbidden();        // or the owner
    $change($admin, $admin, 'viewer')->assertForbidden();        // or themselves
    $change($viewer, $admin, 'viewer')->assertForbidden();
    $change($owner, $admin, 'owner')->assertSessionHasErrors('role');

    $change($owner, $otherAdmin, 'viewer')->assertRedirect();

    expect($membership($otherAdmin)->role)->toBe(FamilyRole::Viewer)
        ->and($membership($owner)->role)->toBe(FamilyRole::Owner)
        ->and(DB::table('audit_logs')->where('action', 'membership.role_changed')->count())->toBe(2);
});

test('removing, leaving, and the owner who cannot leave', function () {
    [$family, $owner] = treeFamily();
    $admin = memberWithRole($family, FamilyRole::Admin);
    $editor = memberWithRole($family, FamilyRole::Editor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $membership = fn (User $user) => $user->memberships()->where('family_id', $family->id)->first();

    $this->actingAs($editor)->delete(route('families.people.destroy', [$family, $membership($viewer)]))->assertForbidden();
    $this->actingAs($admin)->delete(route('families.people.destroy', [$family, $membership($owner)]))->assertForbidden();
    $this->actingAs($admin)->delete(route('families.people.destroy', [$family, $membership($viewer)]))->assertRedirect();
    $this->actingAs($editor)->delete(route('families.people.destroy', [$family, $membership($editor)]))->assertRedirect(route('families.index'));
    $this->actingAs($owner)->delete(route('families.people.destroy', [$family, $membership($owner)]))->assertForbidden();

    expect($membership($viewer))->toBeNull()
        ->and($membership($editor))->toBeNull()
        ->and($membership($owner))->not->toBeNull()
        ->and(User::query()->whereKey($viewer->id)->exists())->toBeTrue();   // the account itself stays

    $this->actingAs($editor)->get(route('families.tree', $family))->assertNotFound();
});

test('ownership is transferred with the password, and there is always exactly one owner', function () {
    [$family, $owner] = treeFamily();
    $admin = memberWithRole($family, FamilyRole::Admin);
    $target = $admin->memberships()->sole();

    $this->actingAs($admin)->post(route('families.people.transfer', [$family, $owner->memberships()->sole()]), ['password' => 'password'])->assertForbidden();
    $this->actingAs($owner)->post(route('families.people.transfer', [$family, $target]), ['password' => 'wrong-password'])->assertSessionHasErrors('password');
    $this->actingAs($owner)->post(route('families.people.transfer', [$family, $target]), ['password' => 'password'])->assertSessionHasNoErrors();

    expect($family->refresh()->owner_id)->toBe($admin->id)
        ->and($admin->memberships()->sole()->role)->toBe(FamilyRole::Owner)
        ->and($owner->memberships()->sole()->role)->toBe(FamilyRole::Admin)
        ->and(FamilyMembership::query()->where('family_id', $family->id)->where('role_id', FamilyRole::Owner->id())->count())->toBe(1);
});

test('an administrator links an account to a person; one account per person', function () {
    [$family, $owner] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $other = memberWithRole($family, FamilyRole::Viewer);
    $person = person($family, 'Person');
    $membership = fn (User $user) => $user->memberships()->where('family_id', $family->id)->sole();

    $this->actingAs($viewer)->patch(route('families.people.link', [$family, $membership($viewer)]), ['member_id' => $person->id])->assertForbidden();
    $this->actingAs($owner)->patch(route('families.people.link', [$family, $membership($viewer)]), ['member_id' => $person->id])->assertRedirect();
    $this->actingAs($owner)->patch(route('families.people.link', [$family, $membership($other)]), ['member_id' => $person->id])->assertRedirect();

    expect($membership($viewer)->linked_member_id)->toBeNull()
        ->and($membership($other)->linked_member_id)->toBe($person->id);
});

// --- Proposals -------------------------------------------------------------------------

test('a contributor\'s edit waits for approval, then is applied with a full trail', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $ahmad = person($family, 'Ahmad', ['living_status' => LivingStatus::Deceased, 'occupation' => 'Farmer']);

    $this->actingAs($contributor)->patch(route('families.members.update', [$family, $ahmad]), [
        'version' => 1, 'full_name' => 'Ahmad bin Ali', 'occupation' => 'Farmer', 'living_status' => 'deceased', 'birth' => ['year' => 1920, 'qualifier' => 'about'],
        'notes' => 'Contributors cannot set notes', 'visibility' => 'private',
    ])->assertRedirect();

    $change = ChangeRequest::query()->sole();

    // Nothing changed yet; only the fields that differ are in the proposal.
    expect($ahmad->refresh()->full_name)->toBe('Ahmad')
        ->and(array_keys($change->payload))->toEqualCanonicalizing(['full_name', 'birth'])
        ->and($change->base_version)->toBe(1);

    Notification::assertSentTo($owner, ChangeRequestSubmitted::class);

    // The reviewer sees current and proposed values side by side.
    $this->actingAs($owner)->get(route('families.changes.index', $family))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('changes/index')
        ->has('pending', 1)
        ->where('pending.0.summary', 'Edit Ahmad')
        ->where('pending.0.can.review', true)
        ->where('pending.0.rows', [
            ['label' => 'Full name', 'old' => 'Ahmad', 'new' => 'Ahmad bin Ali'],
            ['label' => 'Born', 'old' => null, 'new' => 'about 1920'],
        ]));

    $this->post(route('families.changes.approve', [$family, $change]), ['note' => 'Thank you'])->assertSessionHasNoErrors();

    $ahmad->refresh();

    expect($ahmad->full_name)->toBe('Ahmad bin Ali')
        ->and($ahmad->birth->label())->toBe('about 1920')
        ->and($ahmad->notes)->toBeNull()
        ->and($ahmad->visibility)->toBe(Visibility::Family)
        ->and($ahmad->updated_by)->toBe($contributor->id)
        ->and($change->refresh()->status)->toBe('approved')
        ->and($change->reviewer_id)->toBe($owner->id)
        ->and(DB::table('audit_logs')->whereIn('action', ['change_request.submitted', 'change_request.approved', 'member.updated'])->count())->toBe(3);

    Notification::assertSentTo($contributor, ChangeRequestDecided::class);

    // Decided once only.
    $this->post(route('families.changes.approve', [$family, $change]))->assertForbidden();
});

test('a contributor proposes a new relative; approval creates the person and the link', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $ahmad = person($family, 'Ahmad');

    $this->actingAs($contributor)
        ->post(route('families.members.relatives.store', [$family, $ahmad]), ['kind' => 'child', 'member' => ['full_name' => 'Siti', 'gender' => 'female']])
        ->assertRedirect();

    expect(FamilyMember::query()->where('full_name', 'Siti')->exists())->toBeFalse();

    $change = ChangeRequest::query()->sole();
    $this->actingAs($owner)->post(route('families.changes.approve', [$family, $change]))->assertSessionHasNoErrors();

    $siti = FamilyMember::query()->where('full_name', 'Siti')->sole();

    expect(FamilyRelationship::query()->where('person_a_id', $ahmad->id)->where('person_b_id', $siti->id)->exists())->toBeTrue();
});

test('a proposal that would make the tree impossible is refused at approval with the reason', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $father = person($family, 'Bakar');
    $child = person($family, 'Siti');
    parentOf($father, $child);

    $this->actingAs($contributor)
        ->post(route('families.members.relatives.store', [$family, $father]), ['kind' => 'parent', 'existing_member_id' => $child->id])
        ->assertRedirect();

    $change = ChangeRequest::query()->sole();

    $this->actingAs($owner)->post(route('families.changes.approve', [$family, $change]))->assertSessionHasErrors($change->id);

    expect($change->refresh()->status)->toBe('pending')
        ->and(FamilyRelationship::query()->count())->toBe(1);
});

test('a record that changed since the proposal needs a deliberate second approval', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $ahmad = person($family, 'Ahmad');

    $this->actingAs($contributor)->patch(route('families.members.update', [$family, $ahmad]), ['version' => 1, 'full_name' => 'Ahmad bin Ali']);
    $change = ChangeRequest::query()->sole();

    // Meanwhile the owner edits the same person.
    $this->actingAs($owner)->patch(route('families.members.update', [$family, $ahmad]), ['version' => 1, 'full_name' => 'Ahmad', 'occupation' => 'Farmer']);

    $this->get(route('families.changes.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->where('pending.0.conflict', true));

    $this->post(route('families.changes.approve', [$family, $change]))->assertSessionHasErrors($change->id);
    expect($ahmad->refresh()->full_name)->toBe('Ahmad');

    $this->post(route('families.changes.approve', [$family, $change]), ['force' => true])->assertSessionHasNoErrors();

    // The proposed name is applied; the owner's other change is kept.
    expect($ahmad->refresh()->full_name)->toBe('Ahmad bin Ali')->and($ahmad->occupation)->toBe('Farmer');
});

test('declining, withdrawing, and who may decide', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $other = memberWithRole($family, FamilyRole::Contributor);
    $ahmad = person($family, 'Ahmad');

    $propose = function (string $name) use ($contributor, $family, $ahmad): ChangeRequest {
        $this->actingAs($contributor)->patch(route('families.members.update', [$family, $ahmad]), ['version' => $ahmad->refresh()->version, 'full_name' => $name]);

        return ChangeRequest::query()->latest('id')->firstOrFail();
    };

    $first = $propose('First');

    // Not the submitter, not another contributor, not a viewer of someone else's proposal.
    $this->actingAs($contributor)->post(route('families.changes.approve', [$family, $first]))->assertForbidden();
    $this->actingAs($other)->post(route('families.changes.approve', [$family, $first]))->assertForbidden();
    $this->actingAs($other)->delete(route('families.changes.withdraw', [$family, $first]))->assertNotFound();
    $this->actingAs($other)->get(route('families.changes.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->has('pending', 0));

    // An editor reviews.
    $this->actingAs($editor)->post(route('families.changes.reject', [$family, $first]), ['note' => 'Not sure about this'])->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe('rejected')->and($ahmad->refresh()->full_name)->toBe('Ahmad');
    Notification::assertSentTo($contributor, ChangeRequestDecided::class);

    $second = $propose('Second');
    $this->actingAs($contributor)->delete(route('families.changes.withdraw', [$family, $second]))->assertRedirect();
    expect($second->refresh()->status)->toBe('withdrawn');

    // Outsiders: not found.
    [, $outsider] = treeFamily();
    $third = $propose('Third');
    $this->actingAs($outsider)->get(route('families.changes.index', $family))->assertNotFound();
    $this->actingAs($outsider)->post(route('families.changes.approve', [$family, $third]))->assertNotFound();
    expect($third->refresh()->status)->toBe('pending');
});

test('an editor proposes removing a person; only the owner or an administrator can approve it', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $otherEditor = memberWithRole($family, FamilyRole::Editor);
    $ahmad = person($family, 'Ahmad');

    $this->actingAs($editor)->delete(route('families.members.destroy', [$family, $ahmad]))->assertRedirect();
    $change = ChangeRequest::query()->sole();

    expect($change->kind())->toBe('member.delete')->and(FamilyMember::query()->whereKey($ahmad->id)->exists())->toBeTrue();

    $this->actingAs($otherEditor)->post(route('families.changes.approve', [$family, $change]))->assertForbidden();
    $this->actingAs($owner)->post(route('families.changes.approve', [$family, $change]))->assertSessionHasNoErrors();

    expect(FamilyMember::query()->whereKey($ahmad->id)->exists())->toBeFalse();
});

test('the approval policy can make editors propose and let contributors publish', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $contributor = memberWithRole($family, FamilyRole::Contributor);

    $this->actingAs($owner)->patch(route('families.update', $family), [
        'name' => $family->name, 'editors_need_approval' => true, 'contributors_publish_directly' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($editor)->post(route('families.members.store', $family), ['full_name' => 'By Editor']);
    $this->actingAs($contributor)->post(route('families.members.store', $family), ['full_name' => 'By Contributor']);

    expect(FamilyMember::query()->where('full_name', 'By Editor')->exists())->toBeFalse()
        ->and(FamilyMember::query()->where('full_name', 'By Contributor')->exists())->toBeTrue();

    // An editor whose own changes need approval cannot approve others' either.
    $change = ChangeRequest::query()->sole();
    $otherEditor = memberWithRole($family, FamilyRole::Editor);
    $this->actingAs($otherEditor)->post(route('families.changes.approve', [$family, $change]))->assertForbidden();
    $this->actingAs($owner)->post(route('families.changes.approve', [$family, $change]))->assertSessionHasNoErrors();

    expect(FamilyMember::query()->where('full_name', 'By Editor')->exists())->toBeTrue();
});

test('an edit by someone who cannot see a living person\'s details neither reveals nor erases them', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $siti = person($family, 'Siti', [
        'living_status' => LivingStatus::Living, 'birth' => FuzzyDate::year(1990), 'occupation' => 'Nurse',
        'biography' => 'Lives in Kota Belud.', 'notes' => 'Editors only',
    ]);

    // What a form sends when those fields were shown as empty, plus a guess at a hidden one.
    $blind = fn (int $version, string $name): array => [
        'version' => $version, 'full_name' => $name, 'living_status' => 'living',
        'birth' => ['year' => null, 'month' => null, 'day' => null, 'qualifier' => 'exact'],
        'birth_place' => '', 'hometown' => '', 'occupation' => 'Guess', 'education' => '', 'nationality' => '', 'biography' => '',
        'notes' => '', 'visibility' => 'private',
    ];

    $this->actingAs($contributor)->patch(route('families.members.update', [$family, $siti]), $blind(1, 'Siti binti Ali'))->assertRedirect();

    $change = ChangeRequest::query()->sole();
    expect(array_keys($change->payload))->toBe(['full_name']);

    $this->actingAs($contributor)->get(route('families.changes.index', $family))->assertOk()
        ->assertDontSee('1990')->assertDontSee('Nurse')->assertDontSee('Kota Belud')->assertDontSee('Editors only')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pending.0.rows', [
            ['label' => 'Full name', 'old' => 'Siti', 'new' => 'Siti binti Ali'],
        ]));

    // A proposal stored before this rule existed: its hidden fields are not shown to the
    // submitter and are not applied on approval.
    $change->forceFill(['payload' => [...$change->payload, 'occupation' => null, 'biography' => null, 'notes' => null]])->save();

    $this->get(route('families.changes.index', $family))->assertOk()
        ->assertDontSee('Nurse')->assertDontSee('Kota Belud')->assertDontSee('Editors only')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pending.0.rows', 1));

    // The reviewer, who may see them, still sees every row.
    $this->actingAs($owner)->get(route('families.changes.index', $family))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pending.0.rows', 4));

    $this->post(route('families.changes.approve', [$family, $change]))->assertSessionHasNoErrors();

    expect($siti->refresh())
        ->full_name->toBe('Siti binti Ali')
        ->occupation->toBe('Nurse')
        ->biography->toBe('Lives in Kota Belud.')
        ->notes->toBe('Editors only')
        ->and($siti->birth->label())->toBe('1990');

    // Publishing directly does not erase them either.
    $this->patch(route('families.update', $family), ['name' => $family->name, 'contributors_publish_directly' => true])->assertSessionHasNoErrors();
    $this->actingAs($contributor)->patch(route('families.members.update', [$family, $siti]), $blind(2, 'Siti binti Ali Hassan'))->assertRedirect();

    expect($siti->refresh())
        ->full_name->toBe('Siti binti Ali Hassan')
        ->occupation->toBe('Nurse')
        ->biography->toBe('Lives in Kota Belud.')
        ->notes->toBe('Editors only')
        ->visibility->toBe(Visibility::Family)
        ->and($siti->birth->label())->toBe('1990');

    // Someone who sees the details can still change them, and a deceased person's are open to all.
    $this->actingAs($owner)->patch(route('families.members.update', [$family, $siti]), [
        'version' => 3, 'full_name' => 'Siti binti Ali Hassan', 'living_status' => 'living', 'occupation' => 'Matron',
    ])->assertSessionHasNoErrors();
    expect($siti->refresh()->occupation)->toBe('Matron');

    $ahmad = person($family, 'Ahmad', ['living_status' => LivingStatus::Deceased]);
    $this->actingAs($contributor)->patch(route('families.members.update', [$family, $ahmad]), [
        'version' => 1, 'full_name' => 'Ahmad', 'living_status' => 'deceased', 'occupation' => 'Farmer',
    ])->assertRedirect();
    expect($ahmad->refresh()->occupation)->toBe('Farmer');
});

test('a viewer may propose changes to their own profile only', function () {
    Notification::fake();
    [$family] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $me = person($family, 'Me', ['living_status' => LivingStatus::Living]);
    $someone = person($family, 'Someone');
    $viewer->memberships()->sole()->forceFill(['linked_member_id' => $me->id])->save();

    $this->actingAs($viewer)->patch(route('families.members.update', [$family, $me]), ['version' => 1, 'full_name' => 'My Name'])->assertRedirect();
    $this->patch(route('families.members.update', [$family, $someone]), ['version' => 1, 'full_name' => 'Nope'])->assertForbidden();

    expect($me->refresh()->full_name)->toBe('Me')->and(ChangeRequest::query()->count())->toBe(1);
});

test('a contributor proposes a timeline entry', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $contributor = memberWithRole($family, FamilyRole::Contributor);

    $this->actingAs($contributor)->post(route('families.timeline.store', $family), ['title' => 'Family moved', 'category' => 'migration', 'event' => ['year' => 1960]])->assertRedirect();

    expect(TimelineEntry::query()->count())->toBe(0);

    $this->actingAs($owner)->post(route('families.changes.approve', [$family, ChangeRequest::query()->sole()]))->assertSessionHasNoErrors();

    expect(TimelineEntry::query()->sole()->title)->toBe('Family moved');
});

test('proposals about a private person are invisible to reviewers who may not see that person', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    $admin = memberWithRole($family, FamilyRole::Admin);
    $editor = memberWithRole($family, FamilyRole::Editor);
    $secret = person($family, 'Secret', ['visibility' => Visibility::Private]);

    $this->actingAs($owner)->patch(route('families.update', $family), ['name' => $family->name, 'editors_need_approval' => false]);

    // Made by hand: nobody who can see a private record needs approval.
    $change = new ChangeRequest;
    $change->forceFill([
        'family_id' => $family->id, 'submitted_by' => $admin->id, 'target_type' => 'member', 'action' => 'update',
        'target_id' => $secret->id, 'payload' => ['full_name' => 'Renamed'], 'base_version' => 1, 'summary' => 'Edit Secret', 'visibility' => 'private',
    ])->save();

    $this->actingAs($editor)->get(route('families.changes.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->has('pending', 0));
    $this->post(route('families.changes.approve', [$family, $change]))->assertNotFound();

    $this->actingAs($owner)->get(route('families.changes.index', $family))->assertInertia(fn (AssertableInertia $page) => $page->has('pending', 1));
});

// --- Activity and audit ----------------------------------------------------------------

test('changes to the tree are written to the audit log and the activity feed', function () {
    [$family, $owner] = treeFamily();

    $this->actingAs($owner);
    $this->post(route('families.members.store', $family), ['full_name' => 'Ahmad']);
    $ahmad = FamilyMember::query()->sole();
    $this->patch(route('families.members.update', [$family, $ahmad]), ['version' => 1, 'full_name' => 'Ahmad bin Ali', 'occupation' => 'Farmer']);
    $this->post(route('families.members.relatives.store', [$family, $ahmad]), ['kind' => 'child', 'member' => ['full_name' => 'Siti']]);
    $siti = FamilyMember::query()->where('full_name', 'Siti')->sole();
    $this->delete(route('families.members.destroy', [$family, $siti]));

    $actions = DB::table('audit_logs')->where('family_id', $family->id)->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe(['member.created', 'member.updated', 'member.created', 'relationship.created', 'member.deleted']);

    $update = DB::table('audit_logs')->where('action', 'member.updated')->first();

    expect(json_decode($update->old_values, true))->toBe(['full_name' => 'Ahmad', 'occupation' => null])
        ->and(json_decode($update->new_values, true))->toBe(['full_name' => 'Ahmad bin Ali', 'occupation' => 'Farmer'])
        ->and($update->user_id)->toBe($owner->id)
        ->and($update->ip_address)->not->toBeNull()
        ->and($update->request_id)->not->toBeNull();

    $this->get(route('families.activity.index', $family))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('activity/index')
        ->has('activity.data', 5)
        ->where('activity.data.0.summary', 'removed Siti from the tree')
        ->where('activity.data.0.actor', $owner->name)
        ->where('activity.data.4.summary', 'added Ahmad to the tree'));

    $this->get(route('families.activity.audit', $family))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('activity/audit')
        ->has('entries.data', 5)
        ->where('entries.data.0.action', 'member.deleted'));
});

test('who may read the activity feed and the audit log', function () {
    [$family] = treeFamily();
    [, $outsider] = treeFamily();

    $can = fn (FamilyRole $role, string $route) => $this->actingAs(memberWithRole($family, $role))->get(route($route, $family))->status();

    expect($can(FamilyRole::Admin, 'families.activity.index'))->toBe(200)
        ->and($can(FamilyRole::Editor, 'families.activity.index'))->toBe(200)
        ->and($can(FamilyRole::Contributor, 'families.activity.index'))->toBe(403)
        ->and($can(FamilyRole::Viewer, 'families.activity.index'))->toBe(403)
        ->and($can(FamilyRole::Admin, 'families.activity.audit'))->toBe(200)
        ->and($can(FamilyRole::Editor, 'families.activity.audit'))->toBe(403);

    $this->actingAs($outsider)->get(route('families.activity.index', $family))->assertNotFound();
    $this->get(route('families.activity.audit', $family))->assertNotFound();
});

test('activity about a private person is not shown to someone who may not see them', function () {
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);

    $this->actingAs($owner);
    $this->post(route('families.members.store', $family), ['full_name' => 'Open Person']);
    $this->post(route('families.members.store', $family), ['full_name' => 'Secret Person', 'visibility' => 'private']);

    $this->actingAs($editor)->get(route('families.activity.index', $family))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('activity.data', 1)->where('activity.data.0.summary', 'added Open Person to the tree'));
});

test('the dashboard shows recent activity to editors and above only', function () {
    [$family, $owner] = treeFamily();
    $viewer = memberWithRole($family, FamilyRole::Viewer);

    $this->actingAs($owner)->post(route('families.members.store', $family), ['full_name' => 'Ahmad']);
    $this->post(route('families.members.store', $family), ['full_name' => 'Secret Person', 'visibility' => 'private']);

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('overview.activity', 2)
        ->where('overview.activity.1.summary', 'added Ahmad to the tree')
        ->where('overview.activity.1.actor', $owner->name));

    $this->actingAs($viewer)->get(route('families.tree', $family));
    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('overview.activity', null));
});

test('the audit log cannot be changed or deleted, even by the application', function () {
    [$family, $owner] = treeFamily();
    $this->actingAs($owner)->post(route('families.members.store', $family), ['full_name' => 'Ahmad']);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->update(['action' => 'tampered'])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))->toThrow(QueryException::class, 'append-only');
});

test('secrets never reach the audit log', function () {
    [$family, $owner] = treeFamily();
    invite($this, $family, $owner, 'cousin@example.com');

    $logged = DB::table('audit_logs')->get()->map(fn ($row) => $row->old_values.$row->new_values)->implode(' ');

    expect($logged)->not->toContain('token')
        ->and($logged)->not->toContain(FamilyInvitation::query()->sole()->token_hash);
});

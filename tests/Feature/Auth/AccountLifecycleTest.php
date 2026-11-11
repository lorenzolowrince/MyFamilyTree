<?php

use App\Actions\Account\EraseAccount;
use App\Enums\LoginEvent;
use App\Enums\UserStatus;
use App\Models\Family;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Deactivation ----------------------------------------------------------------------

test('a user can deactivate their account with their password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.deactivate'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect($user->refresh()->status)->toBe(UserStatus::Deactivated)
        ->and($user->deletion_scheduled_at)->toBeNull();
});

test('deactivating needs the correct password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->post(route('profile.deactivate'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($user->refresh()->status)->toBe(UserStatus::Active);
});

test('a deactivated user who signs in is sent to the reactivation page and nowhere else', function () {
    $user = User::factory()->deactivated()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('reactivation.show'));
    $this->get(route('profile.edit'))->assertRedirect(route('reactivation.show'));
    $this->get(route('reactivation.show'))->assertOk();
});

test('a deactivated user can reactivate their account', function () {
    $user = User::factory()->deactivated()->create();

    $this->actingAs($user)
        ->post(route('reactivation.store'))
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->status)->toBe(UserStatus::Active);

    $this->get(route('dashboard'))->assertOk();
});

test('a deactivated user can still sign out', function () {
    $this->actingAs(User::factory()->deactivated()->create())
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

// --- Suspension ------------------------------------------------------------------------

test('a suspended user is signed out and told why', function () {
    $user = User::factory()->suspended()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a suspended user cannot reactivate themselves', function () {
    $user = User::factory()->suspended()->create();

    $this->actingAs($user)->post(route('reactivation.store'))->assertRedirect(route('login'));

    expect($user->refresh()->status)->toBe(UserStatus::Suspended);
});

// --- Deletion with grace period --------------------------------------------------------

test('asking to delete an account starts a 14-day grace period', function () {
    $this->freezeSecond();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->status)->toBe(UserStatus::Deactivated)
        ->and($user->deletion_scheduled_at?->equalTo(now()->addDays(14)))->toBeTrue()
        ->and($user->trashed())->toBeFalse();
});

test('signing in during the grace period and reactivating cancels the deletion', function () {
    $user = User::factory()->deactivated()->create(['deletion_scheduled_at' => now()->addDays(10)]);

    $this->actingAs($user)->post(route('reactivation.store'));

    $user->refresh();

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->deletion_scheduled_at)->toBeNull();
});

test('someone who owns a family must hand it over before deleting their account', function () {
    $family = Family::factory()->create();

    $this->actingAs($family->owner)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    $this->assertAuthenticatedAs($family->owner);
    expect($family->owner->refresh()->deletion_scheduled_at)->toBeNull();
});

test('accounts are erased only after the grace period has ended', function () {
    $due = User::factory()->deactivated()->create(['deletion_scheduled_at' => now()->subMinute()]);
    $notYet = User::factory()->deactivated()->create(['deletion_scheduled_at' => now()->addDay()]);
    $untouched = User::factory()->create();

    $this->artisan('accounts:erase-expired')->assertSuccessful();

    expect(User::query()->find($due->id))->toBeNull()
        ->and(User::query()->find($notYet->id))->not->toBeNull()
        ->and(User::query()->find($untouched->id))->not->toBeNull();
});

test('erasing an account removes personal data but keeps the row for attribution', function () {
    $user = User::factory()->withTwoFactor()->create(['name' => 'Siti Aminah', 'email' => 'siti@example.com']);
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => '1234567890']);
    LoginActivity::create(['user_id' => $user->id, 'event' => LoginEvent::Login]);

    app(EraseAccount::class)->handle($user);

    $erased = User::withTrashed()->findOrFail($user->id);

    expect($erased->trashed())->toBeTrue()
        ->and($erased->name)->toBe('Former member')
        ->and($erased->email)->toBe("deleted-{$user->id}@deleted.invalid")
        ->and($erased->password)->toBeNull()
        ->and($erased->two_factor_secret)->toBeNull()
        ->and($erased->two_factor_recovery_codes)->toBeNull()
        ->and($erased->socialAccounts()->count())->toBe(0)
        ->and($erased->loginActivities()->count())->toBe(0);

    // The email address is free to register again.
    $this->post(route('register.store'), [
        'name' => 'Siti Aminah',
        'email' => 'siti@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasNoErrors();
});

test('an erased account cannot sign in', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);

    app(EraseAccount::class)->handle($user);

    $this->post(route('login.store'), ['email' => 'gone@example.com', 'password' => 'password']);
    $this->post(route('login.store'), ['email' => "deleted-{$user->id}@deleted.invalid", 'password' => 'password']);

    $this->assertGuest();
});

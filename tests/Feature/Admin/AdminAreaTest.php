<?php

use App\Enums\UserStatus;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** A Super Administrator who has two-factor on and has just confirmed their password. */
function actingAsSuperAdmin(): User
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    test()->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

    return $admin;
}

// --- Who gets in -----------------------------------------------------------------------

test('guests are sent to sign in', function () {
    $this->get(route('admin.overview'))->assertRedirect(route('login'));
});

test('ordinary users get "not found" for every admin route', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->withSession(['auth.password_confirmed_at' => time()]);

    $target = User::factory()->create();

    $this->get(route('admin.overview'))->assertNotFound();
    $this->get(route('admin.users.index'))->assertNotFound();
    $this->post(route('admin.users.suspend', $target))->assertNotFound();
    $this->post(route('admin.users.reinstate', $target))->assertNotFound();

    expect($target->refresh()->status)->toBe(UserStatus::Active);
});

test('a super administrator without two-factor is sent to turn it on', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.overview'))
        ->assertRedirect(route('security.edit'));
});

test('a super administrator must confirm their password first', function () {
    $this->actingAs(User::factory()->superAdmin()->withTwoFactor()->create())
        ->get(route('admin.overview'))
        ->assertRedirect(route('password.confirm'));
});

test('a suspended super administrator is locked out', function () {
    $this->actingAs(User::factory()->superAdmin()->withTwoFactor()->suspended()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.overview'))
        ->assertRedirect(route('login'));
});

test('every admin route is behind the super administrator, two-factor and password checks', function () {
    $adminRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => Str::startsWith((string) $route->getName(), 'admin.'));

    expect($adminRoutes)->not->toBeEmpty();

    foreach ($adminRoutes as $route) {
        expect($route->gatherMiddleware())->toContain(
            'auth',
            'verified',
            EnsureSuperAdmin::class,
            EnsureTwoFactorEnabled::class,
            RequirePassword::class,
        );
    }
});

// --- Overview --------------------------------------------------------------------------

test('the overview shows platform totals', function () {
    actingAsSuperAdmin();
    User::factory()->count(2)->create();
    User::factory()->suspended()->create();

    $this->get(route('admin.overview'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('admin/overview')
        ->where('stats.users', 4)
        ->where('stats.usersSuspended', 1)
        ->where('stats.families', 0));
});

// --- Users -----------------------------------------------------------------------------

test('the user list can be searched and never includes secrets', function () {
    actingAsSuperAdmin();
    User::factory()->create(['name' => 'Jamilah Lidau', 'email' => 'jamilah@example.com']);
    User::factory()->create(['name' => 'Someone Else']);

    $response = $this->get(route('admin.users.index', ['search' => 'lidau']));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('admin/users')
        ->has('users.data', 1)
        ->where('users.data.0.email', 'jamilah@example.com')
        ->missing('users.data.0.password')
        ->missing('users.data.0.two_factor_secret'));
});

test('search input is treated as text, not as a pattern or SQL', function () {
    actingAsSuperAdmin();
    User::factory()->count(3)->create();

    $this->get(route('admin.users.index', ['search' => "%' OR 1=1 --"]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 0));

    $this->get(route('admin.users.index', ['search' => '%']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('users.data', 0));
});

test('a super administrator can suspend a user, which signs them out everywhere', function () {
    actingAsSuperAdmin();

    $user = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
    ]);

    $this->post(route('admin.users.suspend', $user))->assertRedirect();

    expect($user->refresh()->status)->toBe(UserStatus::Suspended)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('a super administrator can reinstate a suspended user', function () {
    actingAsSuperAdmin();
    $user = User::factory()->suspended()->create();

    $this->post(route('admin.users.reinstate', $user))->assertRedirect();

    expect($user->refresh()->status)->toBe(UserStatus::Active);
});

test('reinstating does not reactivate an account its owner deactivated', function () {
    actingAsSuperAdmin();
    $user = User::factory()->deactivated()->create();

    $this->post(route('admin.users.reinstate', $user));

    expect($user->refresh()->status)->toBe(UserStatus::Deactivated);
});

test('super administrators cannot suspend themselves or each other', function () {
    $admin = actingAsSuperAdmin();
    $colleague = User::factory()->superAdmin()->create();

    $this->post(route('admin.users.suspend', $admin))->assertForbidden();
    $this->post(route('admin.users.suspend', $colleague))->assertForbidden();

    expect($admin->refresh()->status)->toBe(UserStatus::Active)
        ->and($colleague->refresh()->status)->toBe(UserStatus::Active);
});

// --- Granting access -------------------------------------------------------------------

test('super administrator access is granted and revoked from the command line', function () {
    $user = User::factory()->create(['email' => 'staff@example.com']);

    $this->artisan('admin:grant', ['email' => 'staff@example.com'])->assertSuccessful();
    expect($user->refresh()->is_super_admin)->toBeTrue();

    $this->artisan('admin:grant', ['email' => 'staff@example.com', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_super_admin)->toBeFalse();
});

test('the grant command refuses unknown, unverified and inactive accounts', function () {
    $unverified = User::factory()->unverified()->create();
    $suspended = User::factory()->suspended()->create();

    $this->artisan('admin:grant', ['email' => 'nobody@example.com'])->assertFailed();
    $this->artisan('admin:grant', ['email' => $unverified->email])->assertFailed();
    $this->artisan('admin:grant', ['email' => $suspended->email])->assertFailed();

    expect(User::query()->where('is_super_admin', true)->count())->toBe(0);
});

test('the super administrator flag cannot be set through any form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Sneaky',
        'email' => $user->email,
        'is_super_admin' => true,
        'status' => 'active',
    ]);

    $this->post(route('logout'));

    $this->post(route('register.store'), [
        'name' => 'Sneaky Two',
        'email' => 'sneaky@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'is_super_admin' => true,
    ]);

    expect(User::query()->where('is_super_admin', true)->count())->toBe(0);
});

// --- Horizon ---------------------------------------------------------------------------

test('the queue dashboard is for super administrators with two-factor only', function () {
    expect(Gate::forUser(User::factory()->superAdmin()->withTwoFactor()->create())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->superAdmin()->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->withTwoFactor()->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::allows('viewHorizon'))->toBeFalse();
});

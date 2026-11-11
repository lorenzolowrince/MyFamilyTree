<?php

use App\Enums\LoginEvent;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function insertSession(User $user, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => $userAgent,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    return $id;
}

// --- Password policy -------------------------------------------------------------------

test('passwords shorter than 12 characters are rejected at registration', function () {
    $this->post(route('register.store'), [
        'name' => 'Aminah',
        'email' => 'aminah@example.com',
        'password' => 'short-pass',
        'password_confirmation' => 'short-pass',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('a long password needs no special characters', function () {
    $this->post(route('register.store'), [
        'name' => 'Aminah',
        'email' => 'aminah@example.com',
        'password' => 'correct horse battery staple',
        'password_confirmation' => 'correct horse battery staple',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

// --- No account enumeration ------------------------------------------------------------

test('asking for a reset link answers the same whether or not the email has an account', function () {
    Notification::fake();

    $user = User::factory()->create();

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);

    $known->assertRedirect(route('password.request'))->assertSessionHasNoErrors();
    $unknown->assertRedirect(route('password.request'))->assertSessionHasNoErrors();

    expect($unknown->getSession()->get('status'))->toBe($known->getSession()->get('status'));

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

// --- Rate limiting ---------------------------------------------------------------------

test('registration is limited to 5 attempts a minute per email and IP', function () {
    $attempt = fn () => $this->post(route('register.store'), [
        'name' => 'Aminah',
        'email' => 'aminah@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    foreach (range(1, 5) as $ignored) {
        $attempt()->assertSessionHasErrors('password');
    }

    $attempt()->assertTooManyRequests();
});

test('password reset requests are limited to 5 a minute per email and IP', function () {
    Notification::fake();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertTooManyRequests();
});

// --- Sign-in history -------------------------------------------------------------------

test('successful, failed and sign-out events are written to the sign-in history', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    $events = LoginActivity::query()->where('user_id', $user->id)->orderBy('created_at')->pluck('event')->all();

    expect($events)->toEqualCanonicalizing([LoginEvent::Failed, LoginEvent::Login, LoginEvent::Logout])
        ->and($user->refresh()->last_login_at)->not->toBeNull();
});

test('a failed sign-in for an unknown email is recorded without a user', function () {
    $this->post(route('login.store'), ['email' => 'stranger@example.com', 'password' => 'whatever-it-is']);

    $this->assertDatabaseHas('login_activities', [
        'user_id' => null,
        'email_attempted' => 'stranger@example.com',
        'event' => 'failed',
    ]);
});

test('the sign-in history never stores the password that was tried', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'my-secret-guess-123']);

    $row = (array) DB::table('login_activities')->first();

    expect(json_encode($row))->not->toContain('my-secret-guess-123');
});

test('sign-in history older than 12 months is pruned', function () {
    $user = User::factory()->create();

    $old = LoginActivity::create(['user_id' => $user->id, 'event' => LoginEvent::Login]);
    DB::table('login_activities')->where('id', $old->id)->update(['created_at' => now()->subMonths(13)]);
    $recent = LoginActivity::create(['user_id' => $user->id, 'event' => LoginEvent::Login]);

    $this->artisan('model:prune', ['--model' => [LoginActivity::class]])->assertSuccessful();

    expect(LoginActivity::query()->pluck('id')->all())->toBe([$recent->id]);
});

// --- Sessions --------------------------------------------------------------------------

test('resetting a password signs out every existing session', function () {
    Notification::fake();

    $user = User::factory()->create();
    insertSession($user);
    insertSession($user);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    $this->assertDatabaseHas('login_activities', ['user_id' => $user->id, 'event' => 'password_reset']);
});

test('changing the password signs out other devices', function () {
    $user = User::factory()->create();
    insertSession($user);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('the sessions page lists devices and history without exposing session ids', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $sessionId = insertSession($user);
    LoginActivity::create(['user_id' => $user->id, 'event' => LoginEvent::Login, 'ip_address' => '203.0.113.9']);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('sessions.index'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/sessions')
        ->has('sessions', 1)
        ->where('sessions.0.id', hash('sha256', $sessionId))
        ->where('sessions.0.device', 'Chrome on Windows')
        ->has('history', 1)
        ->where('history.0.event', 'login'));

    expect($response->getContent())->not->toContain($sessionId);
});

test('the sessions page asks for the password again first', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('sessions.index'))
        ->assertRedirect(route('password.confirm'));
});

test('a user can sign out one of their other devices', function () {
    $user = User::factory()->create();
    $mine = insertSession($user);
    $alsoMine = insertSession($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('sessions.destroy', hash('sha256', $mine)))
        ->assertRedirect();

    expect(DB::table('sessions')->pluck('id')->all())->toBe([$alsoMine]);
});

test('a user cannot sign out somebody else\'s device', function () {
    $user = User::factory()->create();
    $theirs = insertSession(User::factory()->create());

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('sessions.destroy', hash('sha256', $theirs)))
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', $theirs)->exists())->toBeTrue();
});

test('a user can sign out all other devices at once', function () {
    $user = User::factory()->create();
    insertSession($user);
    insertSession($user);
    $theirs = insertSession(User::factory()->create());

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect();

    expect(DB::table('sessions')->pluck('id')->all())->toBe([$theirs]);
});

// --- Cookies and headers ---------------------------------------------------------------

test('"remember me" lasts 30 days', function () {
    expect(config('auth.guards.web.remember'))->toBe(43200);
});

test('responses carry a nonce-based content security policy', function () {
    $response = $this->get(route('login'));

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("form-action 'self'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{20,}'/")
        ->not->toMatch("/script-src[^;]*'unsafe-inline'/")
        ->not->toMatch("/script-src[^;]*'unsafe-eval'/");

    preg_match("/'nonce-([^']+)'/", $csp, $matches);

    // The inline theme script in the page carries the same nonce.
    expect($response->getContent())->toContain('nonce="'.$matches[1].'"');
});

test('every response gets a different nonce', function () {
    $first = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
    $second = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

test('the browser is only sent the user fields the interface needs', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.two_factor_enabled', true)
        ->where('auth.user.is_super_admin', false)
        ->missing('auth.user.password')
        ->missing('auth.user.two_factor_secret')
        ->missing('auth.user.two_factor_recovery_codes')
        ->missing('auth.user.remember_token')
        ->missing('auth.user.status'));
});

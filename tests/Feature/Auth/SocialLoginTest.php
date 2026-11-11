<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
    ]);
});

/** Make Google "return" this person on the callback. */
function fakeGoogleUser(string $id, string $email, bool $verified = true, string $name = 'Lina Lidau'): void
{
    $user = (new GoogleUser)->setRaw(['email_verified' => $verified])->map([
        'id' => $id,
        'name' => $name,
        'email' => $email,
    ]);

    Socialite::shouldReceive('driver->user')->andReturn($user);
}

test('the Google routes do not exist when Google sign-in is not configured', function () {
    config(['services.google.client_id' => null]);

    $this->get(route('social.redirect', 'google'))->assertNotFound();
    $this->get(route('social.callback', 'google'))->assertNotFound();
});

test('unknown providers are not found', function () {
    $this->get('/auth/facebook/redirect')->assertNotFound();
});

test('the redirect sends the visitor to Google', function () {
    $response = $this->get(route('social.redirect', 'google'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith('https://accounts.google.com/')
        ->toContain('state=');
});

test('a new person can sign up with a verified Google account', function () {
    fakeGoogleUser('g-1001', 'lina@example.com');

    $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'lina@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Lina Lidau')
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->socialAccounts()->where('provider_user_id', 'g-1001')->exists())->toBeTrue();
});

test('an unverified Google email cannot create an account', function () {
    fakeGoogleUser('g-1002', 'lina@example.com', verified: false);

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('a returning Google user is signed in to their linked account', function () {
    $user = User::factory()->withoutPassword()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-2001']);

    // The email at Google may have changed since; the Google id is what identifies them.
    fakeGoogleUser('g-2001', 'changed@example.com');

    $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

test('Google is never linked automatically to an existing account with the same email', function () {
    $existing = User::factory()->create(['email' => 'lina@example.com']);

    fakeGoogleUser('g-attacker', 'lina@example.com');

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0)
        ->and($existing->socialAccounts()->count())->toBe(0);
});

test('Google sign-in still asks for the two-factor code', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-3001']);

    fakeGoogleUser('g-3001', $user->email);

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('a suspended account cannot get in through Google', function () {
    $user = User::factory()->suspended()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-4001']);

    fakeGoogleUser('g-4001', $user->email);

    $this->get(route('social.callback', 'google'));
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a signed-in user can connect Google to their account', function () {
    $user = User::factory()->create();

    fakeGoogleUser('g-5001', 'different-address@example.com');

    $this->actingAs($user)
        ->get(route('social.callback', 'google'))
        ->assertRedirect(route('security.edit'));

    expect($user->socialAccounts()->where('provider_user_id', 'g-5001')->exists())->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

test('a Google account already connected to someone else cannot be connected again', function () {
    $other = User::factory()->create();
    $other->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-6001']);

    $user = User::factory()->create();

    fakeGoogleUser('g-6001', 'whoever@example.com');

    $this->actingAs($user)->get(route('social.callback', 'google'))->assertRedirect(route('security.edit'));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->count())->toBe(0)
        ->and($other->socialAccounts()->count())->toBe(1);
});

test('a forged or expired callback is rejected', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user with a password can disconnect Google', function () {
    $user = User::factory()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-7001']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('social.destroy', 'google'))
        ->assertRedirect(route('security.edit'));

    expect($user->socialAccounts()->count())->toBe(0);
});

test('a Google-only user cannot disconnect Google and lock themselves out', function () {
    $user = User::factory()->withoutPassword()->create();
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-8001']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('social.destroy', 'google'))
        ->assertRedirect(route('security.edit'));

    expect($user->socialAccounts()->count())->toBe(1);
});

test('a Google-only user cannot sign in with an empty password', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => '']);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

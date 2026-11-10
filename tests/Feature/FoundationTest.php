<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the required PostgreSQL extensions are installed', function () {
    $installed = collect(DB::select('SELECT extname FROM pg_extension'))->pluck('extname');

    expect($installed)->toContain('citext', 'pg_trgm', 'unaccent');
});

test('users get time-ordered UUIDv7 primary keys', function () {
    $user = User::factory()->create();

    expect(Str::isUuid($user->id))->toBeTrue()
        ->and($user->id[14])->toBe('7');
});

test('email addresses are unique regardless of letter case', function () {
    User::factory()->create(['email' => 'siti@example.com']);

    User::factory()->create(['email' => 'SITI@Example.com']);
})->throws(UniqueConstraintViolationException::class);

test('passwords are hashed with Argon2id', function () {
    $user = User::factory()->create(['password' => 'a-long-test-password']);

    expect($user->getAttributes()['password'])->toStartWith('$argon2id$')
        ->and(Hash::check('a-long-test-password', $user->password))->toBeTrue();
});

test('responses carry the baseline security headers', function () {
    $this->get('/')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

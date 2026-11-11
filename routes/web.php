<?php

use App\Http\Controllers\Account\ReactivationController;
use App\Http\Controllers\Auth\SocialLoginController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// Sign in with Google. Also used by a signed-in user to connect Google to their account.
Route::middleware('throttle:social-login')->group(function () {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('social.callback');
});

// Shown after signing in to a deactivated account (see EnsureAccountIsActive).
Route::middleware('auth')->group(function () {
    Route::get('account/reactivate', [ReactivationController::class, 'show'])->name('reactivation.show');
    Route::post('account/reactivate', [ReactivationController::class, 'store'])->name('reactivation.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

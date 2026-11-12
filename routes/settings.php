<?php

use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// These routes only ever act on the signed-in user's own account.

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Both ask for the current password in the request itself (re-authentication).
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('settings/profile/deactivate', [ProfileController::class, 'deactivate'])->name('profile.deactivate');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::middleware(RequirePassword::class)->group(function () {
        Route::get('settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('settings/sessions/{session}', [SessionController::class, 'destroy'])
            ->where('session', '[a-f0-9]{64}')
            ->name('sessions.destroy');

        Route::delete('settings/connections/{provider}', [SocialLoginController::class, 'destroy'])->name('social.destroy');
    });

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationController::class, 'update'])->name('notifications.update');
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('settings/notifications/devices', [NotificationController::class, 'subscribe'])->name('notifications.subscribe');
        Route::delete('settings/notifications/devices', [NotificationController::class, 'unsubscribe'])->name('notifications.unsubscribe');
    });
    Route::post('settings/notifications/test', [NotificationController::class, 'test'])
        ->middleware('throttle:5,1')
        ->name('notifications.test');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');

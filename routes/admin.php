<?php

use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
| Platform administration (Super Administrators only).
| Everyone else gets 404. Two-factor authentication and a recent password confirmation are
| required. Controllers still authorise each action (tests/Feature/Admin).
*/

Route::middleware(['auth', 'verified', EnsureSuperAdmin::class, EnsureTwoFactorEnabled::class, RequirePassword::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', OverviewController::class)->name('overview');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('users/{user}/reinstate', [UserController::class, 'reinstate'])->name('users.reinstate');
    });

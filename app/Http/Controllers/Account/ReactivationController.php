<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\ReactivateAccount;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shown when someone signs in to an account they deactivated (or asked to delete).
 * Only ever acts on the signed-in user.
 */
class ReactivationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->status !== UserStatus::Deactivated) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/reactivate-account', [
            'deletionScheduledAt' => $user->deletion_scheduled_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request, ReactivateAccount $reactivate): RedirectResponse
    {
        $reactivate->handle($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome back. Your account is active again.')]);

        return to_route('dashboard');
    }
}

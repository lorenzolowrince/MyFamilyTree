<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LoginEvent;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\LoginActivity;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform overview for Super Administrators: totals only. Platform staff cannot browse a
 * family's records from here (docs/architecture/05-roles-permissions.md).
 */
class OverviewController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('access-admin');

        $since = now()->subDay();

        return Inertia::render('admin/overview', [
            'stats' => [
                'users' => User::query()->count(),
                'usersNewThisWeek' => User::query()->where('created_at', '>=', now()->subWeek())->count(),
                'usersUnverified' => User::query()->whereNull('email_verified_at')->count(),
                'usersSuspended' => User::query()->where('status', UserStatus::Suspended->value)->count(),
                'usersPendingDeletion' => User::query()->whereNotNull('deletion_scheduled_at')->count(),
                'families' => Family::query()->count(),
                'signInsLastDay' => LoginActivity::query()->where('event', LoginEvent::Login->value)->where('created_at', '>=', $since)->count(),
                'failedSignInsLastDay' => LoginActivity::query()->whereIn('event', [LoginEvent::Failed->value, LoginEvent::MfaFailed->value])->where('created_at', '>=', $since)->count(),
                'lockoutsLastDay' => LoginActivity::query()->where('event', LoginEvent::Lockout->value)->where('created_at', '>=', $since)->count(),
            ],
        ]);
    }
}

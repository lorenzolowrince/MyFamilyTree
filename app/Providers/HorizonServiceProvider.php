<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * Outside local development the queue dashboard is for Super Administrators only,
     * and only once they have two-factor authentication switched on.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user !== null
            && $user->is_super_admin
            && $user->isActive()
            && $user->hasEnabledTwoFactorAuthentication());
    }
}

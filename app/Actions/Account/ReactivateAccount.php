<?php

namespace App\Actions\Account;

use App\Enums\UserStatus;
use App\Models\User;

/** Reverses a deactivation and cancels any pending account deletion. */
class ReactivateAccount
{
    public function handle(User $user): void
    {
        if ($user->status !== UserStatus::Deactivated) {
            return;
        }

        $user->forceFill([
            'status' => UserStatus::Active,
            'deletion_scheduled_at' => null,
        ])->save();
    }
}

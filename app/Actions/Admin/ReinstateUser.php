<?php

namespace App\Actions\Admin;

use App\Enums\UserStatus;
use App\Models\User;

/** Lifts a suspension. Does not touch accounts the user deactivated themselves. */
class ReinstateUser
{
    public function handle(User $account): void
    {
        if ($account->status !== UserStatus::Suspended) {
            return;
        }

        $account->forceFill(['status' => UserStatus::Active])->save();
    }
}

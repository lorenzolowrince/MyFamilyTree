<?php

namespace App\Policies;

use App\Models\User;

/**
 * Platform-level account management by Super Administrators.
 * (What a user may do to their own account needs no policy: those routes only ever act on
 * the signed-in user.)
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin;
    }

    /** Super Administrators cannot suspend themselves or each other from the UI. */
    public function suspend(User $user, User $account): bool
    {
        return $user->is_super_admin
            && ! $account->is_super_admin
            && $account->isNot($user);
    }

    public function reinstate(User $user, User $account): bool
    {
        return $user->is_super_admin && $account->isNot($user);
    }
}

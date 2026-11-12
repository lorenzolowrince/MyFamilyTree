<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Erases a user's personal account data once the deletion grace period has passed.
 *
 * The row itself is kept, anonymised and soft-deleted, so records the person contributed to
 * a family tree stay attributed to "Former member" instead of breaking
 * (docs/architecture/06-security.md, "Account deletion").
 */
class EraseAccount
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->socialAccounts()->delete();
            $user->passkeys()->delete();
            $user->pushSubscriptions()->delete();
            $user->loginActivities()->delete();
            $user->memberships()->delete();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $user->email)->delete();

            $user->forceFill([
                'name' => 'Former member',
                'email' => "deleted-{$user->id}@deleted.invalid",
                'email_verified_at' => null,
                'password' => null,
                'remember_token' => null,
                'avatar_path' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'is_super_admin' => false,
                'deletion_scheduled_at' => null,
            ])->save();

            $user->delete();
        });
    }
}

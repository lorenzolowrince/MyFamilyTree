<?php

namespace App\Actions\Account;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Starts the 14-day grace period before an account is erased
 * (docs/architecture/06-security.md, "Account deletion").
 *
 * The account is deactivated straight away. Signing in during the grace period lets the
 * user cancel; after it, `accounts:erase-expired` runs EraseAccount.
 */
class RequestAccountDeletion
{
    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    /**
     * @throws ValidationException when the user still owns a family
     */
    public function handle(User $user): void
    {
        if ($user->ownedFamilies()->exists()) {
            throw ValidationException::withMessages([
                'password' => __('You own a family tree. Transfer its ownership or delete it before deleting your account.'),
            ]);
        }

        $user->forceFill([
            'status' => UserStatus::Deactivated,
            'deletion_scheduled_at' => now()->addDays(User::DELETION_GRACE_DAYS),
        ])->save();

        $this->revokeSessions->all($user);
    }
}

<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs a user out of their devices by deleting rows from the database session store and
 * rotating the "remember me" token, so a stolen cookie cannot sign back in.
 */
class RevokeSessions
{
    /** Sign the user out everywhere, optionally keeping one session (the current one). */
    public function all(User $user, ?string $exceptSessionId = null): int
    {
        $deleted = $this->sessions($user)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return $deleted;
    }

    /**
     * Sign out one session, identified by the SHA-256 of its id. (The raw session id is the
     * cookie secret, so it is never sent to the browser as an identifier.)
     */
    public function one(User $user, string $sessionHash): bool
    {
        $id = $this->sessions($user)->pluck('id')
            ->first(fn (string $id): bool => hash_equals(hash('sha256', $id), $sessionHash));

        if ($id === null) {
            return false;
        }

        return $this->sessions($user)->where('id', $id)->delete() > 0;
    }

    private function sessions(User $user): Builder
    {
        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->id);
    }
}

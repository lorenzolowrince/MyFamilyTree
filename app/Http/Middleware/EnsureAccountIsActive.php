<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies account status to every signed-in request, however the user signed in
 * (password, passkey, Google, "remember me").
 *
 * - Suspended accounts are signed out immediately.
 * - Deactivated accounts can only reach the "reactivate" page and sign out.
 */
class EnsureAccountIsActive
{
    /** Routes a deactivated user may still use. */
    private const ALLOWED_WHILE_DEACTIVATED = ['reactivation.show', 'reactivation.store', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status === UserStatus::Active) {
            return $next($request);
        }

        if ($user->status === UserStatus::Suspended) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('This account has been suspended. Contact support if you think this is a mistake.'),
            ]);
        }

        if ($request->routeIs(...self::ALLOWED_WHILE_DEACTIVATED)) {
            return $next($request);
        }

        return redirect()->route('reactivation.show');
    }
}

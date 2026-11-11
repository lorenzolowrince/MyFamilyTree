<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is required for Super Administrators and for the Owners and
 * Administrators of a family (docs/architecture/06-security.md). Until it is switched on,
 * the protected area sends the user to their security settings.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->mustUseTwoFactor() && ! $user->hasEnabledTwoFactorAuthentication()) {
            if ($request->expectsJson()) {
                abort(403, __('Two-factor authentication is required for this area.'));
            }

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Turn on two-factor authentication to continue. It is required for your role.'),
            ]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}

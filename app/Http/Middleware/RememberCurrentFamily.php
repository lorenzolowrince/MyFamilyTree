<?php

namespace App\Http\Middleware;

use App\Models\Family;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers which family the person last opened, so the main navigation ("My Family Tree",
 * "Family Members") leads back to it. A convenience only: every request is still authorised.
 */
class RememberCurrentFamily
{
    public const SESSION_KEY = 'current_family_id';

    public function __construct(private readonly FamilyAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $family = $request->route('family');
        $user = $request->user();

        if ($family instanceof Family && $user instanceof User && $request->hasSession() && $this->access->isMember($user, $family)) {
            $request->session()->put(self::SESSION_KEY, $family->id);
        }

        return $next($request);
    }
}

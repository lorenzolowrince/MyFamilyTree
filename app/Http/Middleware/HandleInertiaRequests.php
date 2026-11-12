<?php

namespace App\Http\Middleware;

use App\Enums\FamilyPermission;
use App\Enums\MembershipStatus;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $this->user($request->user()),
            ],
            'currentFamily' => fn (): ?array => $this->currentFamily($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The family the navigation points at: the one in the URL, else the one last opened,
     * else the person's first family. Null when they belong to none. The permission list is a
     * hint for showing or hiding buttons; the server authorises every request regardless.
     *
     * @return array{slug: string, name: string, role: string, pendingChanges: int, permissions: list<string>}|null
     */
    private function currentFamily(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            return null;
        }

        $access = app(FamilyAccess::class);
        $routeFamily = $request->route('family');

        $family = $routeFamily instanceof Family && $access->isMember($user, $routeFamily) ? $routeFamily : null;

        if ($family === null) {
            $families = $user->families()
                ->wherePivot('status', MembershipStatus::Active->value)
                ->orderBy('families.created_at')
                ->limit(50)
                ->get();

            $remembered = $request->hasSession() ? $request->session()->get(RememberCurrentFamily::SESSION_KEY) : null;

            $family = $families->firstWhere('id', $remembered) ?? $families->first();
        }

        $role = $family === null ? null : $access->role($user, $family);

        if ($family === null || $role === null) {
            return null;
        }

        return [
            'slug' => $family->slug,
            'name' => $family->name,
            'role' => $role->label(),
            'pendingChanges' => $access->roleHas($role, FamilyPermission::ChangesReview)
                ? ChangeRequest::query()->where('family_id', $family->id)->where('status', 'pending')->where('submitted_by', '<>', $user->id)->count()
                : 0,
            'permissions' => array_values(array_map(
                fn (FamilyPermission $permission): string => $permission->value,
                array_filter(FamilyPermission::cases(), fn (FamilyPermission $permission): bool => $access->roleHas($role, $permission)),
            )),
        ];
    }

    /**
     * Only the fields the interface needs. The model itself is never sent to the browser,
     * so a new column cannot leak by accident.
     *
     * @return array<string, mixed>|null
     */
    private function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'timezone' => $user->timezone,
            'locale' => $user->locale,
            'has_password' => $user->hasPassword(),
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'is_super_admin' => $user->is_super_admin,
        ];
    }
}

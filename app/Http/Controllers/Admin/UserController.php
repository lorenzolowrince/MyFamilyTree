<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ReinstateUser;
use App\Actions\Admin\SuspendUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Account list and suspension for Super Administrators. */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));
        $admin = $request->user();

        $users = User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn ($query) => $query->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'is_super_admin' => $user->is_super_admin,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'deletion_scheduled_at' => $user->deletion_scheduled_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'can' => [
                    'suspend' => $admin->can('suspend', $user),
                    'reinstate' => $admin->can('reinstate', $user),
                ],
            ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'filters' => ['search' => $search],
        ]);
    }

    public function suspend(User $user, SuspendUser $suspend): RedirectResponse
    {
        $this->authorize('suspend', $user);

        $suspend->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been suspended and signed out.', ['name' => $user->name])]);

        return back();
    }

    public function reinstate(User $user, ReinstateUser $reinstate): RedirectResponse
    {
        $this->authorize('reinstate', $user);

        $reinstate->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can sign in again.', ['name' => $user->name])]);

        return back();
    }
}

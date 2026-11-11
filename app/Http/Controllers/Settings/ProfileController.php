<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\DeactivateAccount;
use App\Actions\Account\RequestAccountDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use DateTimeZone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'deletionGraceDays' => User::DELETION_GRACE_DAYS,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Pause the account. Nothing is deleted; signing in again offers to reactivate it.
     */
    public function deactivate(ProfileDeleteRequest $request, DeactivateAccount $deactivate): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        $deactivate->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Ask for the account to be deleted. It is deactivated now and erased after the grace
     * period; signing in before then cancels the request.
     */
    public function destroy(ProfileDeleteRequest $request, RequestAccountDeletion $requestDeletion): RedirectResponse
    {
        $user = $request->user();

        $requestDeletion->handle($user);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}

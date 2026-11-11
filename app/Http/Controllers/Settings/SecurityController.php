<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\RevokeSessions;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $user = $request->user();

        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $user
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'twoFactorRequired' => $user->mustUseTwoFactor(),
            'google' => [
                'available' => SocialProvider::Google->isConfigured(),
                'connected' => $user->socialAccounts()->where('provider', SocialProvider::Google->value)->exists(),
            ],
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password and sign out every other device.
     */
    public function update(PasswordUpdateRequest $request, RevokeSessions $revokeSessions): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        $revokeSessions->all($request->user(), exceptSessionId: $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated. Other devices have been signed out.')]);

        return back();
    }
}

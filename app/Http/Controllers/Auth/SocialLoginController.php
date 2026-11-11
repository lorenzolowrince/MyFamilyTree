<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Sign in with Google (Apple follows the same path later).
 *
 * Security rules (docs/architecture/06-security.md, "Social login"):
 * - Socialite checks the OAuth `state` value, so the callback cannot be forged.
 * - A Google identity is never attached to an existing account automatically. The owner
 *   must sign in to that account first and connect Google from Security settings. Otherwise
 *   anyone controlling a Google address equal to a member's email could take the account over.
 * - Accounts with two-factor authentication still get the two-factor challenge.
 * - Only Google addresses that Google reports as verified can create an account.
 */
class SocialLoginController extends Controller
{
    public function redirect(SocialProvider $provider): HttpResponse
    {
        abort_unless($provider->isConfigured(), 404);

        return Socialite::driver($provider->value)->redirect();
    }

    public function callback(Request $request, SocialProvider $provider): RedirectResponse
    {
        abort_unless($provider->isConfigured(), 404);

        try {
            $providerUser = Socialite::driver($provider->value)->user();
        } catch (Throwable $exception) {
            // Cancelled at the provider, expired state, or a provider error.
            Log::info('Social sign-in did not complete.', ['provider' => $provider->value, 'error' => $exception::class]);

            return $this->fail($request, __(':provider sign-in was cancelled or could not be completed. Please try again.', ['provider' => $provider->label()]));
        }

        $account = SocialAccount::query()
            ->where('provider', $provider->value)
            ->where('provider_user_id', (string) $providerUser->getId())
            ->first();

        if ($request->user() instanceof User) {
            return $this->connect($request->user(), $provider, $providerUser, $account);
        }

        if ($account !== null) {
            return $this->signIn($request, $account->user);
        }

        return $this->register($request, $provider, $providerUser);
    }

    /** Disconnect the provider from the signed-in user's account. */
    public function destroy(Request $request, SocialProvider $provider): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPassword()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Set a password first, otherwise you would be locked out of your account.'),
            ]);

            return to_route('security.edit');
        }

        $user->socialAccounts()->where('provider', $provider->value)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider disconnected.', ['provider' => $provider->label()])]);

        return to_route('security.edit');
    }

    /** A signed-in user connecting the provider to their own account. */
    private function connect(User $user, SocialProvider $provider, ProviderUser $providerUser, ?SocialAccount $existing): RedirectResponse
    {
        if ($existing !== null && $existing->user_id !== $user->id) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('That :provider account is already connected to another account here.', ['provider' => $provider->label()]),
            ]);

            return to_route('security.edit');
        }

        if ($existing === null) {
            $user->socialAccounts()->updateOrCreate(
                ['provider' => $provider->value],
                ['provider_user_id' => (string) $providerUser->getId()],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider connected.', ['provider' => $provider->label()])]);

        return to_route('security.edit');
    }

    private function signIn(Request $request, ?User $user): RedirectResponse
    {
        // Soft-deleted (erased) accounts no longer resolve.
        if ($user === null) {
            return $this->fail($request, __('That account no longer exists.'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Hand over to Fortify's two-factor challenge instead of signing in directly.
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return to_route('two-factor.login');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function register(Request $request, SocialProvider $provider, ProviderUser $providerUser): RedirectResponse
    {
        $email = $providerUser->getEmail();

        if (! is_string($email) || $email === '' || ! $this->emailIsVerified($providerUser)) {
            return $this->fail($request, __('Your :provider account has no verified email address, so it cannot be used to sign up.', ['provider' => $provider->label()]));
        }

        // Includes deactivated and soft-deleted accounts: the address stays taken.
        if (User::withTrashed()->where('email', $email)->exists()) {
            return $this->fail($request, __('An account with this email already exists. Log in with your password, then connect :provider under Settings → Security.', ['provider' => $provider->label()]));
        }

        $user = DB::transaction(function () use ($provider, $providerUser, $email): User {
            $user = new User([
                'name' => $providerUser->getName() ?: $providerUser->getNickname() ?: str($email)->before('@')->toString(),
                'email' => $email,
            ]);
            // Google has already verified this address.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->socialAccounts()->create([
                'provider' => $provider->value,
                'provider_user_id' => (string) $providerUser->getId(),
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    private function emailIsVerified(ProviderUser $providerUser): bool
    {
        $raw = $providerUser instanceof AbstractUser ? $providerUser->getRaw() : [];

        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function fail(Request $request, string $message): RedirectResponse
    {
        if ($request->user() instanceof User) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return to_route('security.edit');
        }

        return to_route('login')->withErrors(['email' => $message]);
    }
}

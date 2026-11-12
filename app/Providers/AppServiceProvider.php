<?php

namespace App\Providers;

use App\Listeners\RecordLoginActivity;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Authorization\FamilyAccess;
use App\Services\Media\ClamAvScanner;
use App\Services\Media\MediaMemo;
use App\Services\Media\NullScanner;
use App\Services\Media\VirusScanner;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: its per-request memory must not leak between queued jobs.
        $this->app->scoped(FamilyAccess::class);

        // One recorder per request, so its audit rows share a request id.
        $this->app->scoped(HistoryRecorder::class);

        // Remember profile pictures and family addresses for one request only.
        $this->app->scoped(MediaMemo::class);

        $this->app->bind(VirusScanner::class, fn (): VirusScanner => config('media.scanner.enabled')
            ? new ClamAvScanner((string) config('media.scanner.host'), (int) config('media.scanner.port'), (int) config('media.scanner.timeout'))
            : new NullScanner);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureEvents();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Long passwords, no forced complexity rules, and (in production) a check against
        // known breached passwords. See docs/architecture/06-security.md.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12),
        );
    }

    /**
     * Platform-level gate. Family-level rules live in Policies.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->is_super_admin && $user->isActive());
    }

    protected function configureEvents(): void
    {
        Event::subscribe(RecordLoginActivity::class);
    }

    protected function configureRateLimiting(): void
    {
        // Registration and password reset: 5 per minute per email + IP, plus a per-IP ceiling.
        RateLimiter::for('account-requests', function (Request $request): array {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

        // Google sign-in round trips: 20 per minute per IP.
        RateLimiter::for('social-login', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));
    }
}

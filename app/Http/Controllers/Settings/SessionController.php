<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\RevokeSessions;
use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use App\Support\UserAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Where you're signed in" and the sign-in history. Sessions live in PostgreSQL so they can
 * be listed and revoked (docs/architecture/06-security.md, "Sessions and cookies").
 */
class SessionController extends Controller
{
    private const HISTORY_LIMIT = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $currentId = $request->session()->getId();

        $sessions = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn (object $session): array => [
                    // Never the raw id: that is the cookie secret.
                    'id' => hash('sha256', (string) $session->id),
                    'device' => UserAgent::describe($session->user_agent),
                    'ip_address' => $session->ip_address,
                    'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                    'is_current' => hash_equals((string) $session->id, $currentId),
                ])
                ->all()
            : [];

        $history = LoginActivity::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (LoginActivity $activity): array => [
                'id' => $activity->id,
                'event' => $activity->event->value,
                'label' => $activity->event->label(),
                'device' => UserAgent::describe($activity->user_agent),
                'ip_address' => $activity->ip_address,
                'created_at' => $activity->created_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('settings/sessions', [
            'sessions' => $sessions,
            'history' => $history,
            'historyRetentionMonths' => LoginActivity::RETENTION_MONTHS,
        ]);
    }

    /** Sign out one other device. */
    public function destroy(Request $request, string $session, RevokeSessions $revokeSessions): RedirectResponse
    {
        abort_if(hash_equals(hash('sha256', $request->session()->getId()), $session), 422, __('Use "Log out" to end your current session.'));

        abort_unless($revokeSessions->one($request->user(), $session), 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('That device has been signed out.')]);

        return back();
    }

    /** Sign out every device except this one. */
    public function destroyOthers(Request $request, RevokeSessions $revokeSessions): RedirectResponse
    {
        $revokeSessions->all($request->user(), exceptSessionId: $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All other devices have been signed out.')]);

        return back();
    }
}

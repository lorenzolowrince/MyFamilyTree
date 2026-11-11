<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Families\FamilyController;
use App\Http\Middleware\RememberCurrentFamily;
use App\Models\Family;
use App\Models\User;
use App\Services\History\FamilyOverview;
use App\Services\Privacy\MemberPrivacy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in person's starting page: the family they last opened at a glance (numbers,
 * birthdays, anniversaries, events, recent additions) and their other family trees.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, FamilyOverview $overview, MemberPrivacy $privacy): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Family::class);

        $families = $user->families()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->orderBy('families.created_at')
            ->get();

        $current = $families->firstWhere('id', $request->session()->get(RememberCurrentFamily::SESSION_KEY)) ?? $families->first();

        return Inertia::render('dashboard', [
            'families' => FamilyController::summaries($user),
            'overview' => $current === null ? null : $overview->for($current, $privacy->viewer($user, $current), $user->timezone),
            'can' => ['createFamily' => $user->can('create', Family::class)],
        ]);
    }
}

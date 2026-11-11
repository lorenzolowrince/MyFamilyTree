<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Families\FamilyController;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in person's starting page: their family trees at a glance. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Family::class);

        return Inertia::render('dashboard', [
            'families' => FamilyController::summaries($user),
            'can' => ['createFamily' => $user->can('create', Family::class)],
        ]);
    }
}

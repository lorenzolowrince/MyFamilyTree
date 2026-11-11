<?php

namespace App\Http\Controllers\Families;

use App\Actions\Families\CreateFamily;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\FamilyRequest;
use App\Models\Family;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in person's family trees, and each tree's basic settings. */
class FamilyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Family::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('families/index', [
            'families' => self::summaries($user),
            'can' => ['create' => $user->can('create', Family::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Family::class);

        return Inertia::render('families/create');
    }

    public function store(FamilyRequest $request, CreateFamily $create): RedirectResponse
    {
        $this->authorize('create', Family::class);

        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, description?: string|null, add_myself?: bool} $data */
        $data = $request->validated();

        $family = $create->handle($user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your family tree is ready. Start by adding people.')]);

        return to_route('families.tree', $family);
    }

    public function show(Family $family): RedirectResponse
    {
        $this->authorize('view', $family);

        return to_route('families.tree', $family);
    }

    public function edit(Family $family): Response
    {
        $this->authorize('update', $family);

        return Inertia::render('families/settings', [
            'family' => [
                'slug' => $family->slug,
                'name' => $family->name,
                'description' => $family->description,
                'cover_url' => CoverController::url($family),
            ],
        ]);
    }

    public function update(FamilyRequest $request, Family $family): RedirectResponse
    {
        $this->authorize('update', $family);

        $family->update($request->safe()->only(['name', 'description']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Family details saved.')]);

        return to_route('families.edit', $family);
    }

    /**
     * The user's active families with headline numbers, for the dashboard and family list.
     *
     * @return list<array<string, mixed>>
     */
    public static function summaries(User $user): array
    {
        $families = $user->families()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->orderBy('families.name')
            ->get();

        if ($families->isEmpty()) {
            return [];
        }

        $stats = DB::table('family_members')
            ->whereIn('family_id', $families->modelKeys())
            ->whereNull('deleted_at')
            ->where('is_placeholder', false)
            ->groupBy('family_id')
            ->selectRaw('family_id, count(*) AS members, max(generation) AS generations')
            ->get()
            ->keyBy('family_id');

        $access = app(FamilyAccess::class);

        return array_values($families->map(fn (Family $family): array => [
            'slug' => $family->slug,
            'name' => $family->name,
            'description' => $family->description,
            'role' => $access->role($user, $family)?->label(),
            'members' => (int) ($stats[$family->id]->members ?? 0),
            'generations' => (int) ($stats[$family->id]->generations ?? 0),
            'cover_url' => CoverController::url($family),
        ])->all());
    }
}

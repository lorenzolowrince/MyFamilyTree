<?php

namespace App\Http\Controllers\Families;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\TreeSliceBuilder;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Services\Privacy\MemberViewer;
use App\Support\FormOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** The interactive family tree: a slice of the tree around one focus person. */
class TreeController extends Controller
{
    private const VIEWS = ['hourglass', 'descendants', 'ancestors'];

    public function show(
        Request $request,
        Family $family,
        FamilyGraphLoader $graphs,
        TreeSliceBuilder $slices,
        MemberPrivacy $privacy,
        MemberPresenter $presenter,
    ): Response {
        $this->authorize('viewAny', [FamilyMember::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $privacy->viewer($user, $family);

        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'hourglass';
        $up = $this->depth($request, 'up', 3);
        $down = $this->depth($request, 'down', 3);

        $focus = $this->focus($request, $family, $viewer);

        $props = [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'view' => $view,
            'depth' => ['up' => $up, 'down' => $down, 'max' => TreeSliceBuilder::MAX_DEPTH],
            'can' => [
                'addPeople' => $user->can('create', [FamilyMember::class, $family]),
                'manageRelationships' => $user->can('manageRelationships', [FamilyMember::class, $family]),
            ],
            'options' => FormOptions::genealogy(),
        ];

        if ($focus === null) {
            return Inertia::render('families/tree', [...$props, 'slice' => null, 'people' => (object) []]);
        }

        $slice = $slices->build($graphs->for($family), $family->id, $focus->id, $up, $down);

        $people = FamilyMember::query()
            ->inFamily($family)
            ->whereIn('id', $slice['people'])
            ->get()
            ->mapWithKeys(fn (FamilyMember $member): array => [$member->id => $presenter->summary($member, $viewer)]);

        unset($slice['people']);

        return Inertia::render('families/tree', [...$props, 'slice' => $slice, 'people' => $people]);
    }

    private function depth(Request $request, string $key, int $default): int
    {
        $value = $request->query($key);

        return is_numeric($value) ? max(0, min(TreeSliceBuilder::MAX_DEPTH, (int) $value)) : $default;
    }

    /**
     * Who the tree is centred on: the person asked for, else the viewer themselves, else the
     * family's root person, else the earliest person in the tree.
     */
    private function focus(Request $request, Family $family, MemberViewer $viewer): ?FamilyMember
    {
        $requested = $request->query('focus');

        $candidates = array_filter([
            is_string($requested) && Str::isUuid($requested) ? $requested : null,
            $viewer->linkedMemberId,
            $family->root_member_id,
        ]);

        foreach ($candidates as $id) {
            $member = FamilyMember::query()->inFamily($family)->find($id);

            if ($member !== null && $viewer->canView($member)) {
                return $member;
            }
        }

        return FamilyMember::query()
            ->inFamily($family)
            ->whereIn('visibility', $viewer->visibleLevels())
            ->where('is_placeholder', false)
            ->orderByRaw('generation NULLS LAST')
            ->orderBy('created_at')
            ->first();
    }
}

<?php

namespace App\Http\Controllers\Families;

use App\Actions\Members\CreateMember;
use App\Actions\Members\DeleteMember;
use App\Actions\Members\UpdateMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Families\MemberRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use App\Services\Genealogy\DuplicateDetector;
use App\Services\Genealogy\RelativesPresenter;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Support\FormOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The people of one family: list and search, profile, add, edit, remove. */
class MemberController extends Controller
{
    public function __construct(
        private readonly MemberPrivacy $privacy,
        private readonly MemberPresenter $presenter,
    ) {}

    public function index(Request $request, Family $family): Response
    {
        $this->authorize('viewAny', [FamilyMember::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $search = trim((string) $request->query('search', ''));

        $members = $this->search($family, $search, $user)
            ->paginate(30)
            ->withQueryString()
            ->through(fn (FamilyMember $member): array => $this->presenter->summary($member, $viewer));

        return Inertia::render('members/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'members' => $members,
            'filters' => ['search' => $search],
            'can' => ['addPeople' => $user->can('create', [FamilyMember::class, $family])],
            'options' => FormOptions::genealogy(),
        ]);
    }

    /** People matching a name, for the "choose an existing person" picker. */
    public function lookup(Request $request, Family $family): JsonResponse
    {
        $this->authorize('viewAny', [FamilyMember::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $search = trim((string) $request->query('search', ''));

        $members = mb_strlen($search) < 2
            ? []
            : $this->search($family, $search, $user)->limit(10)->get()
                ->map(fn (FamilyMember $member): array => $this->presenter->summary($member, $viewer))
                ->all();

        return response()->json(['members' => $members]);
    }

    /** "Is this one of these people?" Checked before a new person is created. */
    public function duplicates(Request $request, Family $family, DuplicateDetector $detector): JsonResponse
    {
        $this->authorize('viewAny', [FamilyMember::class, $family]);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'birth_year' => ['nullable', 'integer', 'min:1', 'max:3000'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);

        $matches = $detector->find($family, (string) $input['name'], isset($input['birth_year']) ? (int) $input['birth_year'] : null)
            // A person the viewer may not see is never revealed by the duplicate check.
            ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
            ->map(fn (FamilyMember $member): array => $this->presenter->summary($member, $viewer))
            ->values();

        return response()->json(['members' => $matches]);
    }

    public function show(Request $request, Family $family, FamilyMember $member, RelativesPresenter $relatives, FamilyAccess $access): Response
    {
        $this->authorize('view', $member);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);

        $member->load(['birthPlace', 'deathPlace', 'hometown']);

        return Inertia::render('members/show', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'member' => $this->presenter->profile($member, $viewer),
            'relatives' => $relatives->for($family, $member, $viewer),
            'isRoot' => $family->root_member_id === $member->id,
            'linkedToMe' => $viewer->isSelf($member),
            'iAmLinked' => $viewer->linkedMemberId !== null,
            'can' => [
                'update' => $user->can('update', $member),
                'delete' => $user->can('delete', $member),
                'addPeople' => $user->can('create', [FamilyMember::class, $family]),
                'manageRelationships' => $user->can('manageRelationships', [FamilyMember::class, $family]),
                'setRoot' => $user->can('update', $family),
            ],
            'options' => FormOptions::genealogy(),
        ]);
    }

    public function store(MemberRequest $request, Family $family, CreateMember $create): RedirectResponse
    {
        $this->authorize('create', [FamilyMember::class, $family]);

        /** @var User $user */
        $user = $request->user();

        $member = $create->handle($family, $user, $request->memberData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added to the tree.', ['name' => $member->full_name])]);

        return to_route('families.members.show', [$family, $member]);
    }

    public function update(MemberRequest $request, Family $family, FamilyMember $member, UpdateMember $update): RedirectResponse
    {
        $this->authorize('update', $member);

        /** @var User $user */
        $user = $request->user();

        $data = $request->memberData();

        // Research notes and visibility are for editors and above, even on your own profile.
        if (! $this->privacy->viewer($user, $family)->canViewNotes($member)) {
            unset($data['notes'], $data['visibility']);
        }

        $update->handle($member, $user, $data, (int) $request->validated('version'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes saved.')]);

        return to_route('families.members.show', [$family, $member]);
    }

    public function destroy(Request $request, Family $family, FamilyMember $member, DeleteMember $delete): RedirectResponse
    {
        $this->authorize('delete', $member);

        /** @var User $user */
        $user = $request->user();

        $delete->handle($member, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was removed from the tree.', ['name' => $member->full_name])]);

        return to_route('families.members.index', $family);
    }

    /** Make this person the default centre of the tree. */
    public function root(Family $family, FamilyMember $member): RedirectResponse
    {
        $this->authorize('update', $family);
        $this->authorize('view', $member);

        $family->forceFill(['root_member_id' => $member->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The tree now opens on :name.', ['name' => $member->full_name])]);

        return back();
    }

    /**
     * Visible people of the family, best name match first.
     *
     * @return Builder<FamilyMember>
     */
    private function search(Family $family, string $search, User $user): Builder
    {
        $viewer = $this->privacy->viewer($user, $family);

        $query = $this->privacy->scopeVisible(FamilyMember::query()->inFamily($family), $viewer);

        if ($search === '') {
            return $query
                ->where('is_placeholder', false)
                ->orderByRaw('generation NULLS LAST')
                ->orderByRaw('birth_date NULLS LAST')
                ->orderBy('full_name');
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $similarity = 'similarity(immutable_unaccent(lower(full_name)), immutable_unaccent(lower(?)))';

        return $query
            ->where(function (Builder $query) use ($search, $like, $similarity): void {
                $query->whereRaw('immutable_unaccent(full_name) ILIKE immutable_unaccent(?)', [$like])
                    ->orWhereRaw('immutable_unaccent(coalesce(nickname, \'\')) ILIKE immutable_unaccent(?)', [$like])
                    ->orWhereRaw('immutable_unaccent(coalesce(maiden_name, \'\')) ILIKE immutable_unaccent(?)', [$like])
                    ->orWhereRaw("search_vector @@ plainto_tsquery('simple', immutable_unaccent(?))", [$search])
                    ->orWhereRaw("{$similarity} >= 0.3", [$search]);
            })
            ->orderByRaw("{$similarity} DESC", [$search])
            ->orderBy('full_name');
    }
}

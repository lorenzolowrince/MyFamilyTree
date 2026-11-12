<?php

namespace App\Http\Controllers\Families;

use App\Enums\FamilyPermission;
use App\Exceptions\InvalidRelationshipException;
use App\Exceptions\VersionConflictException;
use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\User;
use App\Services\Collaboration\ChangeRequests;
use App\Services\Privacy\MemberPrivacy;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The review queue: proposals waiting for approval, and what happened to earlier ones. */
class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly ChangeRequests $changes,
        private readonly MemberPrivacy $privacy,
    ) {}

    public function index(Request $request, Family $family): Response
    {
        $this->authorize('view', $family);

        /** @var User $user */
        $user = $request->user();
        $viewer = $this->privacy->viewer($user, $family);
        $reviewer = $viewer->has(FamilyPermission::ChangesReview);

        // Reviewers see the family's proposals (within what they may see); everyone sees their own.
        $visible = fn (Builder $query) => $query->where('submitted_by', $user->id)
            ->when($reviewer, fn (Builder $query) => $query->orWhereIn('visibility', $viewer->visibleLevels()));

        $base = ChangeRequest::query()->where('family_id', $family->id)->where($visible)->with(['submitter', 'reviewer']);

        return Inertia::render('changes/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'pending' => (clone $base)->where('status', 'pending')->oldest()->limit(100)->get()
                ->map(fn (ChangeRequest $change): array => $this->changes->present($change, $user, $family))->all(),
            'decided' => (clone $base)->where('status', '<>', 'pending')->latest('updated_at')->limit(30)->get()
                ->map(fn (ChangeRequest $change): array => $this->changes->present($change, $user, $family))->all(),
            'isReviewer' => $reviewer,
        ]);
    }

    public function approve(Request $request, Family $family, ChangeRequest $change): RedirectResponse
    {
        $user = $this->reviewer($request, $family, $change);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'force' => ['sometimes', 'boolean']]);

        try {
            $this->changes->approve($change, $user, $data['note'] ?? null, (bool) ($data['force'] ?? false));
        } catch (VersionConflictException) {
            throw ValidationException::withMessages([$change->id => __('This record was changed after the proposal was made. Check the current values, then approve again to apply the proposal on top of them.')]);
        } catch (InvalidRelationshipException|DomainException $exception) {
            throw ValidationException::withMessages([$change->id => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved and applied.')]);

        return back();
    }

    public function reject(Request $request, Family $family, ChangeRequest $change): RedirectResponse
    {
        $user = $this->reviewer($request, $family, $change);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->changes->reject($change, $user, $data['note'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([$change->id => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposal declined.')]);

        return back();
    }

    public function withdraw(Request $request, Family $family, ChangeRequest $change): RedirectResponse
    {
        $this->authorize('view', $family);

        /** @var User $user */
        $user = $request->user();

        abort_unless($change->submitted_by === $user->id, 404);

        $this->changes->withdraw($change);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Proposal withdrawn.')]);

        return back();
    }

    private function reviewer(Request $request, Family $family, ChangeRequest $change): User
    {
        $this->authorize('view', $family);

        /** @var User $user */
        $user = $request->user();

        $viewer = $this->privacy->viewer($user, $family);

        // A proposal about a record this person may not see does not exist for them.
        abort_unless($change->submitted_by === $user->id || in_array($change->visibility, $viewer->visibleLevels(), true), 404);
        abort_unless($this->changes->canReview($user, $change, $family), 403, __('You cannot decide this proposal.'));

        return $user;
    }
}

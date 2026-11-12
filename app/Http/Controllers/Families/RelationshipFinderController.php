<?php

namespace App\Http\Controllers\Families;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\RelationshipCalculator;
use App\Services\Genealogy\RelationshipLabeler;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Services\Privacy\MemberViewer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "How are these two people related?"
 *
 * Both people must be ones the viewer may see. Someone on the path between them whom the
 * viewer may not see appears as "Private relative": the shape of the answer is shown, the
 * person is not.
 */
class RelationshipFinderController extends Controller
{
    public function show(
        Request $request,
        Family $family,
        FamilyGraphLoader $graphs,
        RelationshipCalculator $calculator,
        RelationshipLabeler $labeler,
        MemberPrivacy $privacy,
        MemberPresenter $presenter,
    ): Response {
        $this->authorize('viewAny', [FamilyMember::class, $family]);

        /** @var User $user */
        $user = $request->user();
        $viewer = $privacy->viewer($user, $family);

        // Without a first person chosen, start from the viewer's own place in the tree.
        $from = $this->person($family, $viewer, $request->query('from') ?? $viewer->linkedMemberId);
        $to = $this->person($family, $viewer, $request->query('to'));

        $results = [];

        if ($from !== null && $to !== null) {
            $relations = $calculator->between($graphs->for($family), $from->id, $to->id);

            $ids = [];

            foreach ($relations as $relation) {
                $ids = [...$ids, ...array_column($relation['path'], 'id'), ...$relation['apexes']];
            }

            $people = FamilyMember::query()->inFamily($family)->whereIn('id', array_unique($ids))->get()->keyBy('id');

            // The gender of someone hidden is not given away through the wording.
            $genders = $people
                ->filter(fn (FamilyMember $member): bool => $viewer->canView($member))
                ->map(fn (FamilyMember $member): ?string => $member->gender?->value)
                ->all();

            $card = fn (string $id): array => $people->has($id)
                ? $presenter->summary($people[$id], $viewer)
                : MemberPresenter::hidden($id);

            foreach ($relations as $relation) {
                $onPath = array_column($relation['path'], 'id');

                $results[] = [
                    'kind' => $relation['kind'],
                    'label' => $labeler->label($relation, $genders),
                    'uncertain' => $relation['uncertain'],
                    'path' => array_map(fn (array $step): array => [
                        'person' => $card($step['id']),
                        'via' => $step['via'],
                        'subtype' => $step['subtype'],
                    ], $relation['path']),
                    // The other half of the couple both lines descend from.
                    'also' => array_values(array_map($card, array_diff($relation['apexes'], $onPath))),
                ];
            }
        }

        return Inertia::render('relationships/finder', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'from' => $from === null ? null : $presenter->summary($from, $viewer),
            'to' => $to === null ? null : $presenter->summary($to, $viewer),
            'results' => $results,
        ]);
    }

    private function person(Family $family, MemberViewer $viewer, mixed $id): ?FamilyMember
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        $member = FamilyMember::query()->inFamily($family)->find($id);

        return $member !== null && $viewer->canView($member) ? $member : null;
    }
}

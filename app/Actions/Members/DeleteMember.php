<?php

namespace App\Actions\Members;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyRelationship;
use App\Models\User;
use App\Services\Genealogy\GraphChange;
use Illuminate\Support\Facades\DB;

/**
 * Removes a person from the tree, together with their relationship links. Both are soft
 * deletes with the same timestamp, so the removal can be undone as one step.
 */
class DeleteMember
{
    public function __construct(private readonly GraphChange $graphChange) {}

    public function handle(FamilyMember $member, User $actor): void
    {
        $family = $member->family()->firstOrFail();

        $this->graphChange->run($family, function () use ($member, $actor, $family): void {
            $now = now();

            FamilyRelationship::query()
                ->where('family_id', $family->id)
                ->where(fn ($query) => $query->where('person_a_id', $member->id)->orWhere('person_b_id', $member->id))
                ->toBase()
                ->update(['deleted_at' => $now, 'updated_by' => $actor->id]);

            DB::table('family_user')
                ->where('family_id', $family->id)
                ->where('linked_member_id', $member->id)
                ->update(['linked_member_id' => null]);

            if ($family->root_member_id === $member->id) {
                $next = FamilyMember::query()
                    ->inFamily($family)
                    ->whereKeyNot($member->id)
                    ->where('is_placeholder', false)
                    ->orderBy('generation')
                    ->orderBy('created_at')
                    ->value('id');

                Family::query()->whereKey($family->id)->toBase()->update(['root_member_id' => $next]);
            }

            $member->updated_by = $actor->id;
            $member->forceFill(['deleted_at' => $now])->save();

            Family::bumpGraphVersion($family->id);
        });
    }
}

<?php

namespace App\Actions\Members;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Adds a person to a family tree. Linking them to relatives is a separate step. */
class CreateMember
{
    use FillsMemberAttributes;

    /**
     * @param  array<string, mixed>  $data  validated member fields
     */
    public function handle(Family $family, ?User $actor, array $data, bool $placeholder = false): FamilyMember
    {
        return DB::transaction(function () use ($family, $actor, $data, $placeholder): FamilyMember {
            $member = new FamilyMember;
            $member->family_id = $family->id;
            $member->is_placeholder = $placeholder;
            $member->created_by = $actor?->id;
            $member->updated_by = $actor?->id;

            $this->fillMember($member, $family, $data);
            $member->save();

            // The first person added becomes the default focus of the tree.
            if (! $placeholder) {
                Family::query()->whereKey($family->id)->whereNull('root_member_id')->toBase()->update(['root_member_id' => $member->id]);
            }

            return $member;
        });
    }
}

<?php

namespace App\Actions\Members;

use App\Exceptions\VersionConflictException;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves changes to a person. Uses optimistic locking: the save only succeeds if nobody else
 * saved the record since this editor loaded it.
 */
class UpdateMember
{
    use FillsMemberAttributes;

    /**
     * @param  array<string, mixed>  $data  validated member fields
     *
     * @throws VersionConflictException
     */
    public function handle(FamilyMember $member, User $actor, array $data, int $expectedVersion): FamilyMember
    {
        return DB::transaction(function () use ($member, $actor, $data, $expectedVersion): FamilyMember {
            $fresh = FamilyMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($fresh->version !== $expectedVersion) {
                throw new VersionConflictException;
            }

            $this->fillMember($fresh, $fresh->family()->firstOrFail(), $data);

            // Naming an "Unknown parent" turns the placeholder into a real person.
            if ($fresh->is_placeholder && $fresh->isDirty('full_name')) {
                $fresh->is_placeholder = false;
            }

            if ($fresh->isDirty()) {
                $fresh->version = $expectedVersion + 1;
                $fresh->updated_by = $actor->id;
                $fresh->save();
            }

            return $fresh;
        });
    }
}

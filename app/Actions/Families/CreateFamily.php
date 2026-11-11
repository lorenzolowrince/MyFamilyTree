<?php

namespace App\Actions\Families;

use App\Actions\Members\CreateMember;
use App\Enums\FamilyRole;
use App\Enums\LivingStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\Authorization\FamilyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a new family tree. The creator becomes its Owner and, if they wish, the first person
 * in the tree ("this is me").
 */
class CreateFamily
{
    public function __construct(
        private readonly CreateMember $createMember,
        private readonly FamilyAccess $access,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, add_myself?: bool}  $data
     */
    public function handle(User $owner, array $data): Family
    {
        $family = DB::transaction(function () use ($owner, $data): Family {
            $family = new Family([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
            ]);
            $family->owner_id = $owner->id;
            $family->save();

            $membership = new FamilyMembership;
            $membership->family_id = $family->id;
            $membership->user_id = $owner->id;
            $membership->role = FamilyRole::Owner;
            $membership->forceFill(['joined_at' => now()])->save();

            if ($data['add_myself'] ?? false) {
                $me = $this->createMember->handle($family, $owner, [
                    'full_name' => $owner->name,
                    'living_status' => LivingStatus::Living->value,
                ]);

                $membership->forceFill(['linked_member_id' => $me->id])->save();
                $family->forceFill(['root_member_id' => $me->id])->save();
            }

            return $family;
        });

        $this->access->forgetMemberships();

        return $family;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'family';
        $slug = $base;

        while (Family::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}

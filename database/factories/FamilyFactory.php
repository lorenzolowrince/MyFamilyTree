<?php

namespace Database\Factories;

use App\Enums\FamilyRole;
use App\Enums\MembershipStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->lastName().' Family';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => null,
            'owner_id' => User::factory(),
        ];
    }

    /** Every family's owner also holds the Owner membership. */
    public function configure(): static
    {
        return $this->afterCreating(function (Family $family): void {
            static::addMember($family, $family->owner, FamilyRole::Owner);
        });
    }

    /** Test helper: give a user a role in a family. */
    public static function addMember(
        Family $family,
        User $user,
        FamilyRole $role,
        MembershipStatus $status = MembershipStatus::Active,
    ): FamilyMembership {
        $membership = new FamilyMembership;
        $membership->family_id = $family->id;
        $membership->user_id = $user->id;
        $membership->role = $role;
        $membership->status = $status;
        $membership->forceFill(['joined_at' => now()])->save();

        return $membership;
    }
}

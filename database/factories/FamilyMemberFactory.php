<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyMember>
 */
class FamilyMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'full_name' => fake()->name(),
            'gender' => fake()->randomElement([Gender::Male, Gender::Female]),
            'living_status' => LivingStatus::Unknown,
        ];
    }

    public function born(int $year): static
    {
        return $this->state(fn (): array => ['birth' => FuzzyDate::year($year)]);
    }

    public function living(): static
    {
        return $this->state(fn (): array => ['living_status' => LivingStatus::Living]);
    }

    public function deceased(?int $year = null): static
    {
        return $this->state(fn (): array => [
            'living_status' => LivingStatus::Deceased,
            'death' => $year === null ? FuzzyDate::unknown() : FuzzyDate::year($year),
        ]);
    }

    public function visibility(Visibility $visibility): static
    {
        return $this->state(fn (): array => ['visibility' => $visibility]);
    }
}

<?php

namespace Database\Seeders;

use App\Actions\Families\CreateFamily;
use App\Actions\Members\CreateMember;
use App\Actions\Relationships\LinkParentAndChild;
use App\Actions\Relationships\LinkPartners;
use App\Enums\ParentSubtype;
use App\Jobs\RecomputeGenerations;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A demonstration family for local development and automated tests: four generations, two
 * marriages (so half-siblings), an adoption, a step-parent, a cousin marriage, a living
 * generation and a private record. Never run in production.
 *
 *   Gen 1  Lidau ═ Rumind
 *   Gen 2  Gimbang ═(1) Sarah (divorced) ═(2) Linah          Jaineh ═ Paulus
 *   Gen 3  Daniel, Maria (with Sarah) · Josie (with Linah) · Anna (adopted)      Rita
 *   Gen 4  Daniel ═ Rita (cousins) → Adam, Eve · Maria → Noah (private)
 */
class DemoFamilySeeder extends Seeder
{
    public const FAMILY_NAME = 'Keluarga Lidau (Demo)';

    /** @var array<string, FamilyMember> */
    private array $people = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo family must never be seeded in production.');
        }

        $owner = User::query()->where('email', 'test@example.com')->first()
            ?? User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);

        $existing = Family::query()->where('owner_id', $owner->id)->where('name', self::FAMILY_NAME)->first();

        if ($existing !== null && $existing->members()->exists()) {
            return;
        }

        // An empty shell (left by rolling the people tables back) is replaced.
        $existing?->forceDelete();

        $family = app(CreateFamily::class)->handle($owner, [
            'name' => self::FAMILY_NAME,
            'description' => 'A sample tree for trying the app: four generations, two marriages, an adoption and a cousin marriage.',
        ]);

        // name => [gender, born, died (true = deceased, date unknown), living, extra]
        $people = [
            'Lidau bin Gunsalam' => ['male', 1898, 1971],
            'Rumind binti Sadang' => ['female', 1903, 1984],
            'Gimbang Lidau' => ['male', 1925, 1999],
            'Sarah binti Majin' => ['female', 1930, 2005],
            'Linah binti Kumin' => ['female', 1938, true],
            'Jaineh Lidau' => ['female', 1928, 2010],
            'Paulus Gontusan' => ['male', 1924, 1990],
            'Daniel Gimbang' => ['male', 1952, null],
            'Maria Gimbang' => ['female', 1955, null],
            'Josie Gimbang' => ['female', 1968, null],
            'Anna Gimbang' => ['female', 1972, null],
            'Rita Paulus' => ['female', 1956, null],
            'Adam Daniel' => ['male', 1980, null],
            'Eve Daniel' => ['female', 1984, null],
            'Noah Maria' => ['male', 1982, null, ['visibility' => 'private']],
        ];

        foreach ($people as $name => $facts) {
            $this->people[$name] = app(CreateMember::class)->handle($family, $owner, [
                'full_name' => $name,
                'gender' => $facts[0],
                'birth' => ['year' => $facts[1], 'qualifier' => $facts[1] < 1930 ? 'about' : 'exact'],
                'living_status' => $facts[2] === null ? 'living' : 'deceased',
                'death' => is_int($facts[2]) ? ['year' => $facts[2]] : null,
                'birth_place' => $facts[1] < 1960 ? 'Kampung Sinakagon, Ranau' : 'Kota Kinabalu',
                ...($facts[3] ?? []),
            ]);
        }

        $this->couple('Lidau bin Gunsalam', 'Rumind binti Sadang', ['start' => ['year' => 1923]]);
        $this->children('Lidau bin Gunsalam', 'Rumind binti Sadang', ['Gimbang Lidau', 'Jaineh Lidau']);

        $this->couple('Gimbang Lidau', 'Sarah binti Majin', ['status' => 'divorced', 'start' => ['year' => 1950], 'end' => ['year' => 1965]]);
        $this->couple('Gimbang Lidau', 'Linah binti Kumin', ['start' => ['year' => 1966]]);
        $this->children('Gimbang Lidau', 'Sarah binti Majin', ['Daniel Gimbang', 'Maria Gimbang']);
        $this->children('Gimbang Lidau', 'Linah binti Kumin', ['Josie Gimbang']);
        $this->children('Gimbang Lidau', 'Linah binti Kumin', ['Anna Gimbang'], ParentSubtype::Adoptive);

        // Linah is step-mother to the children of the first marriage.
        $this->parent('Linah binti Kumin', 'Daniel Gimbang', ParentSubtype::Step);

        $this->couple('Jaineh Lidau', 'Paulus Gontusan', ['start' => ['year' => 1949]]);
        $this->children('Jaineh Lidau', 'Paulus Gontusan', ['Rita Paulus']);

        // First cousins who married.
        $this->couple('Daniel Gimbang', 'Rita Paulus', ['start' => ['year' => 1978]]);
        $this->children('Daniel Gimbang', 'Rita Paulus', ['Adam Daniel', 'Eve Daniel']);

        $this->parent('Maria Gimbang', 'Noah Maria');

        $family->forceFill(['root_member_id' => $this->people['Gimbang Lidau']->id])->save();

        RecomputeGenerations::dispatchSync($family->id);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function couple(string $one, string $other, array $details = []): void
    {
        $a = $this->people[$one];

        app(LinkPartners::class)->handle($a->family()->firstOrFail(), null, $a, $this->people[$other], $details);
    }

    /**
     * @param  list<string>  $children
     */
    private function children(string $father, string $mother, array $children, ParentSubtype $subtype = ParentSubtype::Biological): void
    {
        foreach ($children as $child) {
            $this->parent($father, $child, $subtype);
            $this->parent($mother, $child, $subtype);
        }
    }

    private function parent(string $parent, string $child, ParentSubtype $subtype = ParentSubtype::Biological): void
    {
        $person = $this->people[$parent];

        app(LinkParentAndChild::class)->handle($person->family()->firstOrFail(), null, $person, $this->people[$child], $subtype);
    }
}

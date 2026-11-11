<?php

namespace App\Jobs;

use App\Models\Family;
use App\Services\Genealogy\FamilyGraphLoader;
use App\Services\Genealogy\GenerationCalculator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the derived `generation` number of everyone in a family after the tree's shape
 * changed. Only rows whose value actually changed are written.
 */
class RecomputeGenerations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Several quick edits to one family collapse into one pending job. */
    public int $uniqueFor = 60;

    public function __construct(public readonly string $familyId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->familyId;
    }

    public function handle(FamilyGraphLoader $graphs, GenerationCalculator $calculator): void
    {
        $family = Family::query()->find($this->familyId);

        if ($family === null) {
            return;
        }

        $generations = $calculator->calculate($graphs->for($family));

        $current = DB::table('family_members')
            ->where('family_id', $family->id)
            ->whereNull('deleted_at')
            ->pluck('generation', 'id');

        /** @var array<string, list<string>> $changes generation ('' = none) => member ids */
        $changes = [];

        foreach ($generations as $id => $generation) {
            $existing = $current[$id] ?? null;

            if (($existing === null ? null : (int) $existing) !== $generation) {
                $changes[(string) $generation][] = $id;
            }
        }

        foreach ($changes as $generation => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::table('family_members')
                    ->where('family_id', $family->id)
                    ->whereIn('id', $chunk)
                    ->update(['generation' => $generation === '' ? null : (int) $generation]);
            }
        }
    }
}

<?php

namespace App\Services\Genealogy;

use App\Models\Family;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Loads a family's graph, cached under the family's `graph_version`. Any change to a person
 * or relationship bumps the version, so a stale graph is never served.
 *
 * @phpstan-import-type Edge from FamilyGraph
 */
class FamilyGraphLoader
{
    private const TTL_SECONDS = 86400;

    public function for(Family $family): FamilyGraph
    {
        $version = $family->currentGraphVersion();

        /** @var array{members: list<string>, edges: list<Edge>} $data */
        $data = Cache::remember(
            "family:{$family->id}:graph:{$version}",
            self::TTL_SECONDS,
            fn (): array => $this->load($family->id),
        );

        return new FamilyGraph($data['members'], $data['edges']);
    }

    /**
     * @return array{members: list<string>, edges: list<Edge>}
     */
    private function load(string $familyId): array
    {
        $members = DB::table('family_members')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $edges = DB::table('family_relationships')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'type', 'person_a_id', 'person_b_id', 'subtype', 'status', 'certainty', 'sequence', 'start_date'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'type' => (string) $row->type,
                'a' => (string) $row->person_a_id,
                'b' => (string) $row->person_b_id,
                'subtype' => (string) $row->subtype,
                'status' => $row->status === null ? null : (string) $row->status,
                'certainty' => (string) $row->certainty,
                'sequence' => $row->sequence === null ? null : (int) $row->sequence,
                'start' => $row->start_date === null ? null : (string) $row->start_date,
            ])
            ->all();

        return ['members' => array_values($members), 'edges' => array_values($edges)];
    }
}

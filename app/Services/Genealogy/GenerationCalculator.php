<?php

namespace App\Services\Genealogy;

/**
 * Works out each person's generation number (docs/architecture/03-relationship-modelling.md):
 *
 * - the oldest known ancestors are generation 1, their children 2, and so on;
 * - when a person's parents are in different generations, the deeper one counts;
 * - someone with no parents in the tree takes their partner's generation;
 * - someone connected to nobody has no generation (null).
 */
final class GenerationCalculator
{
    /**
     * @return array<string, int|null> member id => generation
     */
    public function calculate(FamilyGraph $graph): array
    {
        $ids = $graph->memberIds();

        /** @var array<string, int|null> $generation */
        $generation = [];

        foreach ($ids as $id) {
            $connected = $graph->parentLinks($id) !== [] || $graph->childLinks($id) !== [] || $graph->partnerLinks($id) !== [];
            $generation[$id] = $connected ? 1 : null;
        }

        // Values only ever grow, and never beyond the number of people, so this settles.
        // The pass limit is a guard against a cycle that slipped past the database trigger.
        $passes = 0;
        $limit = count($ids) + 2;

        do {
            $changed = false;

            foreach ($ids as $id) {
                if ($generation[$id] === null) {
                    continue;
                }

                $parents = $graph->parentIds($id);
                $candidate = 1;

                if ($parents !== []) {
                    foreach ($parents as $parent) {
                        $candidate = max($candidate, ($generation[$parent] ?? 0) + 1);
                    }
                } else {
                    foreach ($graph->partnerIds($id) as $partner) {
                        // Only partners who have parents of their own pull a parentless person down.
                        if ($graph->parentLinks($partner) !== []) {
                            $candidate = max($candidate, $generation[$partner] ?? 1);
                        }
                    }
                }

                if ($candidate > $generation[$id]) {
                    $generation[$id] = $candidate;
                    $changed = true;
                }
            }
        } while ($changed && ++$passes < $limit);

        return $generation;
    }
}

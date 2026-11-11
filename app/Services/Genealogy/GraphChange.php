<?php

namespace App\Services\Genealogy;

use App\Exceptions\InvalidRelationshipException;
use App\Jobs\RecomputeGenerations;
use App\Models\Family;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Wraps every change to the shape of a tree.
 *
 * - One change at a time per family (the family row is locked), so two people editing at once
 *   cannot together create a structure that each check alone would allow.
 * - A refusal by the database trigger becomes a friendly InvalidRelationshipException.
 * - Generations are recomputed afterwards, in the background.
 */
class GraphChange
{
    /**
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     *
     * @throws InvalidRelationshipException
     */
    public function run(Family $family, Closure $change): mixed
    {
        try {
            $result = DB::transaction(function () use ($family, $change): mixed {
                Family::query()->whereKey($family->id)->lockForUpdate()->toBase()->value('id');

                return $change();
            });
        } catch (QueryException $exception) {
            throw InvalidRelationshipException::fromQueryException($exception) ?? $exception;
        }

        RecomputeGenerations::dispatch($family->id);

        return $result;
    }
}

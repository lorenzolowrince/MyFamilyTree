<?php

namespace App\Support\FuzzyDate;

use App\Enums\DatePrecision;
use App\Enums\DateQualifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Adds the five columns that hold one fuzzy date (docs/architecture/02-database.md):
 * `{prefix}_date`, `_date_to`, `_date_precision`, `_date_qualifier`, `_date_text`.
 */
final class FuzzyDateColumns
{
    public static function add(Blueprint $table, string $prefix): void
    {
        $table->date("{$prefix}_date")->nullable();
        $table->date("{$prefix}_date_to")->nullable();
        $table->text("{$prefix}_date_precision")->default(DatePrecision::Unknown->value);
        $table->text("{$prefix}_date_qualifier")->default(DateQualifier::Exact->value);
        $table->text("{$prefix}_date_text")->nullable();
    }

    /** CHECK constraints for one fuzzy date. Call after the table exists. */
    public static function constrain(string $table, string $prefix): void
    {
        $precisions = self::quoted(array_column(DatePrecision::cases(), 'value'));
        $qualifiers = self::quoted(array_column(DateQualifier::cases(), 'value'));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$prefix}_date_check CHECK (
            {$prefix}_date_precision IN ({$precisions})
            AND {$prefix}_date_qualifier IN ({$qualifiers})
            AND ({$prefix}_date IS NOT NULL OR {$prefix}_date_precision = 'unknown')
            AND ({$prefix}_date_to IS NULL OR ({$prefix}_date IS NOT NULL AND {$prefix}_date_to >= {$prefix}_date))
        )");
    }

    /** @param list<string> $values */
    public static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));
    }
}

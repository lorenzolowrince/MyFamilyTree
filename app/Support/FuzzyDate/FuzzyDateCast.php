<?php

namespace App\Support\FuzzyDate;

use App\Enums\DatePrecision;
use App\Enums\DateQualifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts the five `{prefix}_date*` columns to one FuzzyDate. The attribute name is the prefix:
 * `'birth' => FuzzyDateCast::class` reads and writes `birth_date`, `birth_date_to`, ...
 *
 * @implements CastsAttributes<FuzzyDate, FuzzyDate|null>
 */
class FuzzyDateCast implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): FuzzyDate
    {
        $date = $attributes["{$key}_date"] ?? null;
        $dateTo = $attributes["{$key}_date_to"] ?? null;

        return new FuzzyDate(
            $date === null ? null : CarbonImmutable::parse((string) $date, 'UTC')->startOfDay(),
            $dateTo === null ? null : CarbonImmutable::parse((string) $dateTo, 'UTC')->startOfDay(),
            DatePrecision::tryFrom((string) ($attributes["{$key}_date_precision"] ?? '')) ?? DatePrecision::Unknown,
            DateQualifier::tryFrom((string) ($attributes["{$key}_date_qualifier"] ?? '')) ?? DateQualifier::Exact,
            isset($attributes["{$key}_date_text"]) ? (string) $attributes["{$key}_date_text"] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $value ??= FuzzyDate::unknown();

        // @phpstan-ignore instanceof.alwaysTrue (the type is only promised by PHPDoc)
        if (! $value instanceof FuzzyDate) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a FuzzyDate.");
        }

        return [
            "{$key}_date" => $value->date?->toDateString(),
            "{$key}_date_to" => $value->dateTo?->toDateString(),
            "{$key}_date_precision" => ($value->date === null ? DatePrecision::Unknown : $value->precision)->value,
            "{$key}_date_qualifier" => $value->qualifier->value,
            "{$key}_date_text" => $value->text,
        ];
    }
}

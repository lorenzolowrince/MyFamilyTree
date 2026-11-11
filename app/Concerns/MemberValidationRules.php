<?php

namespace App\Concerns;

use App\Enums\DateQualifier;
use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\Visibility;
use App\Support\FuzzyDate\FuzzyDate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation shared by every form that creates or edits a person
 * (docs/architecture/02-database.md, "Validation rules").
 */
trait MemberValidationRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function memberRules(string $prefix = '', bool $nameRequired = true): array
    {
        return [
            // Any script is welcome (Jawi, Chinese, Tamil...); control characters are not.
            "{$prefix}full_name" => [$nameRequired ? 'required' : 'sometimes', 'string', 'min:1', 'max:200', 'not_regex:/[\p{Cc}<>]/u'],
            "{$prefix}given_names" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}surname" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}maiden_name" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}nickname" => ['sometimes', 'nullable', 'string', 'max:100'],
            "{$prefix}gender" => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            "{$prefix}living_status" => ['sometimes', Rule::enum(LivingStatus::class)],
            "{$prefix}visibility" => ['sometimes', Rule::enum(Visibility::class)],
            ...$this->fuzzyDateRules("{$prefix}birth"),
            ...$this->fuzzyDateRules("{$prefix}death"),
            "{$prefix}birth_place" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}death_place" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}hometown" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}occupation" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}education" => ['sometimes', 'nullable', 'string', 'max:200'],
            "{$prefix}nationality" => ['sometimes', 'nullable', 'string', 'max:100'],
            "{$prefix}biography" => ['sometimes', 'nullable', 'string', 'max:20000'],
            "{$prefix}notes" => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function fuzzyDateRules(string $field): array
    {
        $thisYear = (int) now()->year;

        return [
            $field => ['sometimes', 'nullable', 'array'],
            "{$field}.year" => ['nullable', 'integer', 'min:1', "max:{$thisYear}"],
            "{$field}.month" => ['nullable', 'integer', 'min:1', 'max:12'],
            "{$field}.day" => ['nullable', 'integer', 'min:1', 'max:31'],
            "{$field}.qualifier" => ['nullable', Rule::enum(DateQualifier::class)],
            "{$field}.year_to" => ['nullable', 'integer', 'min:1', "max:{$thisYear}"],
            "{$field}.decade" => ['nullable', 'boolean'],
            "{$field}.text" => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * Checks that need more than one field: real calendar days, and death not before birth.
     *
     * @param  array<string, mixed>  $member  the submitted member fields
     */
    protected function validateMemberDates(Validator $validator, array $member, string $prefix = ''): void
    {
        foreach (['birth', 'death'] as $field) {
            $date = $member[$field] ?? null;

            if (! is_array($date)) {
                continue;
            }

            $year = $date['year'] ?? null;
            $month = $date['month'] ?? null;
            $day = $date['day'] ?? null;

            if (is_numeric($month) && ! is_numeric($year)) {
                $validator->errors()->add("{$prefix}{$field}.year", __('Enter the year as well.'));
            } elseif (is_numeric($day) && ! is_numeric($month)) {
                $validator->errors()->add("{$prefix}{$field}.month", __('Enter the month as well.'));
            } elseif (is_numeric($year) && is_numeric($month) && is_numeric($day) && ! checkdate((int) $month, (int) $day, (int) $year)) {
                $validator->errors()->add("{$prefix}{$field}.day", __('That day does not exist in that month.'));
            }

            if (($date['qualifier'] ?? null) === DateQualifier::Between->value
                && is_numeric($year)
                && (! is_numeric($date['year_to'] ?? null) || (int) $date['year_to'] < (int) $year)) {
                $validator->errors()->add("{$prefix}{$field}.year_to", __('Enter an end year that is not before the first year.'));
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $birth = FuzzyDate::fromInput(is_array($member['birth'] ?? null) ? $member['birth'] : null);
        $death = FuzzyDate::fromInput(is_array($member['death'] ?? null) ? $member['death'] : null);

        if ($death->isDefinitelyBefore($birth)) {
            $validator->errors()->add("{$prefix}death.year", __('The date of death cannot be before the date of birth.'));
        }
    }
}

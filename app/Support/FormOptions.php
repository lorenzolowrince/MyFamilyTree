<?php

namespace App\Support;

use App\Enums\Certainty;
use App\Enums\DateQualifier;
use App\Enums\Gender;
use App\Enums\LivingStatus;
use App\Enums\ParentSubtype;
use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;
use App\Enums\Visibility;
use Illuminate\Support\Str;

/** The choices offered by the member and relationship forms, straight from the enums. */
final class FormOptions
{
    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function genealogy(): array
    {
        return [
            'genders' => self::plain(Gender::cases()),
            'livingStatuses' => self::plain(LivingStatus::cases()),
            'qualifiers' => self::plain(DateQualifier::cases()),
            'certainties' => self::plain(Certainty::cases()),
            'visibilities' => array_map(fn (Visibility $case): array => ['value' => $case->value, 'label' => $case->label()], Visibility::cases()),
            'parentSubtypes' => array_map(fn (ParentSubtype $case): array => ['value' => $case->value, 'label' => $case->label()], ParentSubtype::cases()),
            'partnershipSubtypes' => array_map(fn (PartnershipSubtype $case): array => ['value' => $case->value, 'label' => $case->label()], PartnershipSubtype::cases()),
            'partnershipStatuses' => array_map(fn (PartnershipStatus $case): array => ['value' => $case->value, 'label' => $case->label()], PartnershipStatus::cases()),
        ];
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function plain(array $cases): array
    {
        return array_values(array_map(
            fn (\BackedEnum $case): array => ['value' => (string) $case->value, 'label' => Str::headline((string) $case->value)],
            $cases,
        ));
    }
}

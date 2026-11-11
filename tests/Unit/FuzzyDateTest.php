<?php

use App\Enums\DatePrecision;
use App\Enums\DateQualifier;
use App\Support\FuzzyDate\FuzzyDate;

test('a full date keeps day precision', function () {
    $date = FuzzyDate::fromInput(['year' => 1920, 'month' => 3, 'day' => 12]);

    expect($date->precision)->toBe(DatePrecision::Day)
        ->and($date->date?->toDateString())->toBe('1920-03-12')
        ->and($date->label())->toBe('12 Mar 1920');
});

test('a year alone is stored as the first day of that year', function () {
    $date = FuzzyDate::fromInput(['year' => '1920', 'qualifier' => 'about']);

    expect($date->precision)->toBe(DatePrecision::Year)
        ->and($date->date?->toDateString())->toBe('1920-01-01')
        ->and($date->label())->toBe('about 1920');
});

test('month and year', function () {
    $date = FuzzyDate::fromInput(['year' => 1935, 'month' => 9]);

    expect($date->precision)->toBe(DatePrecision::Month)->and($date->label())->toBe('Sep 1935');
});

test('a day without a month is ignored', function () {
    expect(FuzzyDate::fromInput(['year' => 1935, 'day' => 9])->precision)->toBe(DatePrecision::Year);
});

test('a decade', function () {
    $date = FuzzyDate::fromInput(['year' => 1927, 'decade' => true]);

    expect($date->precision)->toBe(DatePrecision::Decade)
        ->and($date->date?->toDateString())->toBe('1920-01-01')
        ->and($date->label())->toBe('1920s')
        ->and($date->latest()?->toDateString())->toBe('1929-12-31');
});

test('a range', function () {
    $date = FuzzyDate::fromInput(['year' => 1890, 'qualifier' => 'between', 'year_to' => 1895]);

    expect($date->qualifier)->toBe(DateQualifier::Between)
        ->and($date->dateTo?->toDateString())->toBe('1895-12-31')
        ->and($date->label())->toBe('between 1890 and 1895');
});

test('before and after', function () {
    expect(FuzzyDate::fromInput(['year' => 1945, 'qualifier' => 'before'])->label())->toBe('before 1945')
        ->and(FuzzyDate::fromInput(['year' => 1945, 'qualifier' => 'after'])->label())->toBe('after 1945');
});

test('only a phrase', function () {
    $date = FuzzyDate::fromInput(['text' => 'during the Japanese occupation']);

    expect($date->isKnown())->toBeFalse()
        ->and($date->isEmpty())->toBeFalse()
        ->and($date->label())->toBe('during the Japanese occupation');
});

test('nothing at all', function () {
    expect(FuzzyDate::fromInput(null)->isEmpty())->toBeTrue()
        ->and(FuzzyDate::fromInput([])->isEmpty())->toBeTrue()
        ->and(FuzzyDate::unknown()->label())->toBeNull();
});

test('the form shape round-trips', function () {
    $input = ['year' => 1920, 'month' => 3, 'day' => 12, 'qualifier' => 'exact', 'year_to' => null, 'decade' => false, 'text' => null];

    expect(FuzzyDate::fromInput(FuzzyDate::fromInput($input)->toArray())->toArray())
        ->toBe([...$input, 'label' => '12 Mar 1920']);
});

test('comparison allows for uncertainty', function () {
    $born1920 = FuzzyDate::year(1920);

    expect(FuzzyDate::year(1919)->isDefinitelyBefore($born1920))->toBeTrue()
        ->and(FuzzyDate::year(1920)->isDefinitelyBefore($born1920))->toBeFalse()
        ->and(FuzzyDate::day(1920, 6, 1)->isDefinitelyBefore($born1920))->toBeFalse()
        ->and(FuzzyDate::year(1910, DateQualifier::After)->isDefinitelyBefore($born1920))->toBeFalse()
        ->and(FuzzyDate::unknown()->isDefinitelyBefore($born1920))->toBeFalse();
});

test('year only hides the day and month', function () {
    $masked = FuzzyDate::day(1990, 7, 23)->yearOnly();

    expect($masked->toArray()['month'])->toBeNull()
        ->and($masked->toArray()['day'])->toBeNull()
        ->and($masked->yearValue())->toBe(1990);
});

<?php

namespace App\Support\FuzzyDate;

use App\Enums\DatePrecision;
use App\Enums\DateQualifier;
use Carbon\CarbonImmutable;

/**
 * A historical date that may be incomplete or uncertain: "12 March 1920", "about 1920",
 * "before 1945", "between 1890 and 1895", "1920s", or just a phrase.
 *
 * `date` is always the earliest possible day (used for sorting and searching); `dateTo` is
 * only set for ranges.
 */
final readonly class FuzzyDate
{
    public function __construct(
        public ?CarbonImmutable $date = null,
        public ?CarbonImmutable $dateTo = null,
        public DatePrecision $precision = DatePrecision::Unknown,
        public DateQualifier $qualifier = DateQualifier::Exact,
        public ?string $text = null,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }

    public static function year(int $year, DateQualifier $qualifier = DateQualifier::Exact): self
    {
        return new self(self::make($year, 1, 1), null, DatePrecision::Year, $qualifier);
    }

    public static function day(int $year, int $month, int $day): self
    {
        return new self(self::make($year, $month, $day), null, DatePrecision::Day);
    }

    /**
     * Build from validated form input:
     * `{year, month, day, qualifier, year_to, decade, text}` (all optional).
     *
     * @param  array<string, mixed>|null  $input
     */
    public static function fromInput(?array $input): self
    {
        if ($input === null) {
            return self::unknown();
        }

        $text = isset($input['text']) && trim((string) $input['text']) !== '' ? trim((string) $input['text']) : null;
        $year = self::intOrNull($input['year'] ?? null);

        if ($year === null) {
            return new self(text: $text);
        }

        $month = self::intOrNull($input['month'] ?? null);
        $day = $month === null ? null : self::intOrNull($input['day'] ?? null);
        $qualifier = DateQualifier::tryFrom((string) ($input['qualifier'] ?? '')) ?? DateQualifier::Exact;

        $precision = match (true) {
            (bool) ($input['decade'] ?? false) => DatePrecision::Decade,
            $day !== null => DatePrecision::Day,
            $month !== null => DatePrecision::Month,
            default => DatePrecision::Year,
        };

        if ($precision === DatePrecision::Decade) {
            $year = intdiv($year, 10) * 10;
            $month = $day = null;
        }

        $date = self::make($year, $month ?? 1, $day ?? 1);

        $dateTo = null;
        $yearTo = self::intOrNull($input['year_to'] ?? null);

        if ($qualifier === DateQualifier::Between && $yearTo !== null && $yearTo >= $year) {
            $dateTo = self::make($yearTo, 12, 31);
        } elseif ($qualifier === DateQualifier::Between) {
            $qualifier = DateQualifier::Exact;
        }

        return new self($date, $dateTo, $precision, $qualifier, $text);
    }

    public function isKnown(): bool
    {
        return $this->date !== null;
    }

    public function isEmpty(): bool
    {
        return $this->date === null && $this->text === null;
    }

    public function yearValue(): ?int
    {
        return $this->date?->year;
    }

    /** The first day this date could be. */
    public function earliest(): ?CarbonImmutable
    {
        return $this->date;
    }

    /** The last day this date could be (ignoring before/after, which are open-ended). */
    public function latest(): ?CarbonImmutable
    {
        if ($this->date === null) {
            return null;
        }

        if ($this->dateTo !== null) {
            return $this->dateTo;
        }

        return match ($this->precision) {
            DatePrecision::Day => $this->date,
            DatePrecision::Month => $this->date->endOfMonth()->startOfDay(),
            DatePrecision::Decade => $this->date->addYears(9)->endOfYear()->startOfDay(),
            default => $this->date->endOfYear()->startOfDay(),
        };
    }

    /** True when this date is certainly earlier than the other one. */
    public function isDefinitelyBefore(self $other): bool
    {
        if ($this->qualifier === DateQualifier::After || $other->qualifier === DateQualifier::Before) {
            return false;
        }

        $latest = $this->latest();
        $earliest = $other->earliest();

        return $latest !== null && $earliest !== null && $latest->lt($earliest);
    }

    /** Only the year, for people whose exact dates the viewer may not see. */
    public function yearOnly(): self
    {
        return $this->date === null
            ? self::unknown()
            : new self($this->date->startOfYear(), null, DatePrecision::Year, DateQualifier::About);
    }

    public function label(): ?string
    {
        if ($this->date === null) {
            return $this->text;
        }

        $base = match ($this->precision) {
            DatePrecision::Day => $this->date->format('j M Y'),
            DatePrecision::Month => $this->date->format('M Y'),
            DatePrecision::Decade => $this->date->format('Y').'s',
            default => $this->date->format('Y'),
        };

        return match ($this->qualifier) {
            DateQualifier::Exact => $base,
            DateQualifier::About => "about {$base}",
            DateQualifier::Estimated => "estimated {$base}",
            DateQualifier::Calculated => "calculated {$base}",
            DateQualifier::Before => "before {$base}",
            DateQualifier::After => "after {$base}",
            DateQualifier::Between => $this->dateTo === null ? $base : "between {$base} and ".$this->dateTo->format('Y'),
        };
    }

    /**
     * The shape the date form reads and writes.
     *
     * @return array{year: int|null, month: int|null, day: int|null, qualifier: string, year_to: int|null, decade: bool, text: string|null, label: string|null}
     */
    public function toArray(): array
    {
        return [
            'year' => $this->date?->year,
            'month' => in_array($this->precision, [DatePrecision::Day, DatePrecision::Month], true) ? $this->date?->month : null,
            'day' => $this->precision === DatePrecision::Day ? $this->date?->day : null,
            'qualifier' => $this->qualifier->value,
            'year_to' => $this->dateTo?->year,
            'decade' => $this->precision === DatePrecision::Decade,
            'text' => $this->text,
            'label' => $this->label(),
        ];
    }

    private static function make(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::createFromDate($year, $month, $day, 'UTC')->startOfDay();
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

import { useId } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { FuzzyDateValue } from '@/types';

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const QUALIFIERS: { value: string; label: string }[] = [
    { value: 'exact', label: 'Exactly' },
    { value: 'about', label: 'About' },
    { value: 'before', label: 'Before' },
    { value: 'after', label: 'After' },
    { value: 'between', label: 'Between' },
    { value: 'estimated', label: 'Estimated' },
];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function toNumber(value: string): number | null {
    const parsed = Number.parseInt(value, 10);

    return Number.isNaN(parsed) ? null : parsed;
}

/**
 * A date that may be incomplete: only a year, a month and year, "about", "before",
 * a range of years, or just a description. Everything is optional.
 */
export function FuzzyDateInput({
    label,
    value,
    onChange,
    errors = {},
    errorPrefix,
    className,
}: {
    label: string;
    value: FuzzyDateValue;
    onChange: (value: FuzzyDateValue) => void;
    /** All form errors; the ones under `errorPrefix` are shown here. */
    errors?: Partial<Record<string, string>>;
    errorPrefix: string;
    className?: string;
}) {
    const id = useId();
    const set = (patch: Partial<FuzzyDateValue>) =>
        onChange({ ...value, ...patch });

    const message = ['year', 'month', 'day', 'year_to', 'qualifier', 'text']
        .map((part) => errors[`${errorPrefix}.${part}`])
        .find(Boolean);

    return (
        <fieldset className={cn('grid gap-2', className)}>
            <legend className="mb-2 text-sm leading-none font-medium">
                {label}
            </legend>
            <div className="grid grid-cols-[minmax(0,7rem)_minmax(0,4rem)_minmax(0,1fr)_minmax(0,5rem)] gap-2">
                <div className="grid gap-1">
                    <Label
                        htmlFor={`${id}-qualifier`}
                        className="text-xs font-normal text-muted-foreground"
                    >
                        How sure
                    </Label>
                    <select
                        id={`${id}-qualifier`}
                        className={selectClass}
                        value={value.qualifier}
                        onChange={(event) =>
                            set({ qualifier: event.target.value })
                        }
                    >
                        {QUALIFIERS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label
                        htmlFor={`${id}-day`}
                        className="text-xs font-normal text-muted-foreground"
                    >
                        Day
                    </Label>
                    <Input
                        id={`${id}-day`}
                        inputMode="numeric"
                        maxLength={2}
                        value={value.day ?? ''}
                        onChange={(event) =>
                            set({ day: toNumber(event.target.value) })
                        }
                    />
                </div>
                <div className="grid gap-1">
                    <Label
                        htmlFor={`${id}-month`}
                        className="text-xs font-normal text-muted-foreground"
                    >
                        Month
                    </Label>
                    <select
                        id={`${id}-month`}
                        className={selectClass}
                        value={value.month ?? ''}
                        onChange={(event) =>
                            set({ month: toNumber(event.target.value) })
                        }
                    >
                        <option value="">Not known</option>
                        {MONTHS.map((month, index) => (
                            <option key={month} value={index + 1}>
                                {month}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label
                        htmlFor={`${id}-year`}
                        className="text-xs font-normal text-muted-foreground"
                    >
                        Year
                    </Label>
                    <Input
                        id={`${id}-year`}
                        inputMode="numeric"
                        maxLength={4}
                        value={value.year ?? ''}
                        onChange={(event) =>
                            set({ year: toNumber(event.target.value) })
                        }
                    />
                </div>
            </div>

            {value.qualifier === 'between' && (
                <div className="grid max-w-40 gap-1">
                    <Label
                        htmlFor={`${id}-year-to`}
                        className="text-xs font-normal text-muted-foreground"
                    >
                        And year
                    </Label>
                    <Input
                        id={`${id}-year-to`}
                        inputMode="numeric"
                        maxLength={4}
                        value={value.year_to ?? ''}
                        onChange={(event) =>
                            set({ year_to: toNumber(event.target.value) })
                        }
                    />
                </div>
            )}

            <div className="grid gap-1">
                <Label
                    htmlFor={`${id}-text`}
                    className="text-xs font-normal text-muted-foreground"
                >
                    Or describe it (optional)
                </Label>
                <Input
                    id={`${id}-text`}
                    maxLength={200}
                    placeholder="e.g. during the Japanese occupation"
                    value={value.text ?? ''}
                    onChange={(event) =>
                        set({ text: event.target.value || null })
                    }
                />
            </div>

            <InputError message={message} />
        </fieldset>
    );
}

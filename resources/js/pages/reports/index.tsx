import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, FileText, Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { useRefreshWhileChecking } from '@/components/media/media-status';
import { fileSize } from '@/components/media/upload-dialog';
import { MemberMultiPicker } from '@/components/members/member-multi-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatDateTime } from '@/lib/dates';
import { destroy, store } from '@/routes/families/reports';
import type { FamilyRef, MemberSummary } from '@/types';

type ReportType = {
    value: string;
    label: string;
    /** About one person (or, for the chart, starting from one). */
    personal: boolean;
    generations: boolean;
};

type Report = {
    id: string;
    type: string;
    title: string;
    status: 'pending' | 'processing' | 'ready' | 'failed' | 'expired';
    error: string | null;
    size_bytes: number | null;
    created_at: string | null;
    expires_at: string;
    download_url: string | null;
};

type Props = {
    family: FamilyRef;
    reports: Report[];
    types: ReportType[];
    about: MemberSummary | null;
    limits: {
        generations: number;
        chart_generations: number;
        keep_days: number;
    };
};

const DESCRIPTIONS: Record<string, string> = {
    tree: 'A chart of one person, their partners and the generations below them.',
    descendants: 'Everyone descended from one person, as a numbered outline.',
    ancestors:
        'The parents, grandparents and earlier generations of one person.',
    individual: 'Everything recorded about one person on a page or two.',
    directory:
        'Everyone in the family in alphabetical order, with dates and places.',
    timeline: "The family's history in date order.",
};

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function ReportsIndex({
    family,
    reports,
    types,
    about,
    limits,
}: Props) {
    const { auth } = usePage().props;
    const [person, setPerson] = useState<MemberSummary | null>(about);
    const form = useForm({
        type: types[0]?.value ?? 'tree',
        member_id: about?.id ?? (null as string | null),
        generations: 4,
        from: '',
        to: '',
    });
    const errors = form.errors as Partial<Record<string, string>>;
    const type = types.find((item) => item.value === form.data.type);
    const maxGenerations =
        form.data.type === 'tree'
            ? limits.chart_generations
            : limits.generations;

    useRefreshWhileChecking(
        reports.some(
            (report) =>
                report.status === 'pending' || report.status === 'processing',
        ),
        ['reports'],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            member_id: person?.id ?? null,
            generations: Math.min(data.generations, maxGenerations),
            from: data.from === '' ? null : data.from,
            to: data.to === '' ? null : data.to,
        }));
        form.post(store.url(family.slug), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`${family.name} – Reports`} />

            <div className="mx-auto grid w-full max-w-3xl gap-8 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Reports
                    </h1>
                    <p className="text-muted-foreground">
                        PDF reports to print or keep. A report shows what you
                        are allowed to see, and only you can download it.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="grid gap-4 rounded-xl border p-4"
                    noValidate
                >
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Kind of report
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {types.map((item) => (
                                <label
                                    key={item.value}
                                    className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-checked:border-primary has-checked:bg-primary/5"
                                >
                                    <input
                                        type="radio"
                                        name="type"
                                        className="mt-0.5"
                                        value={item.value}
                                        checked={form.data.type === item.value}
                                        onChange={() =>
                                            form.setData('type', item.value)
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            {item.label}
                                        </span>
                                        <span className="block text-muted-foreground">
                                            {DESCRIPTIONS[item.value]}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.type} />
                    </fieldset>

                    {type?.personal && (
                        <div>
                            <MemberMultiPicker
                                family={family}
                                label={
                                    form.data.type === 'tree'
                                        ? 'Start the chart from'
                                        : 'About'
                                }
                                value={person ? [person] : []}
                                onChange={(people) =>
                                    setPerson(people[people.length - 1] ?? null)
                                }
                            />
                            <InputError
                                message={errors.member_id}
                                className="mt-2"
                            />
                        </div>
                    )}

                    {type?.generations && (
                        <div className="grid max-w-48 gap-2">
                            <Label htmlFor="report-generations">
                                Generations
                            </Label>
                            <select
                                id="report-generations"
                                className={fieldClass}
                                value={Math.min(
                                    form.data.generations,
                                    maxGenerations,
                                )}
                                onChange={(event) =>
                                    form.setData(
                                        'generations',
                                        Number(event.target.value),
                                    )
                                }
                            >
                                {Array.from(
                                    { length: maxGenerations },
                                    (_, number) => number + 1,
                                ).map((number) => (
                                    <option key={number} value={number}>
                                        {number}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.generations} />
                        </div>
                    )}

                    {form.data.type === 'timeline' && (
                        <div className="flex flex-wrap gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="report-from">
                                    From year (optional)
                                </Label>
                                <Input
                                    id="report-from"
                                    inputMode="numeric"
                                    maxLength={4}
                                    className="w-28"
                                    value={form.data.from}
                                    onChange={(event) =>
                                        form.setData('from', event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="report-to">
                                    To year (optional)
                                </Label>
                                <Input
                                    id="report-to"
                                    inputMode="numeric"
                                    maxLength={4}
                                    className="w-28"
                                    value={form.data.to}
                                    onChange={(event) =>
                                        form.setData('to', event.target.value)
                                    }
                                />
                            </div>
                            <InputError
                                message={errors.from ?? errors.to}
                                className="basis-full"
                            />
                        </div>
                    )}

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            <FileText aria-hidden="true" />
                            Make report
                        </Button>
                    </div>
                </form>

                <section
                    aria-labelledby="reports-heading"
                    className="grid gap-3"
                >
                    <div>
                        <h2
                            id="reports-heading"
                            className="text-base font-medium"
                        >
                            Your reports
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Kept for {limits.keep_days} days, then deleted.
                        </p>
                    </div>

                    {reports.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            You have not made any reports yet.
                        </p>
                    ) : (
                        <ul className="grid gap-2" aria-live="polite">
                            {reports.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium">
                                            {report.title}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {report.created_at &&
                                                formatDateTime(
                                                    report.created_at,
                                                    auth.user.timezone,
                                                )}
                                            {report.status === 'ready' &&
                                                ` · ${fileSize(report.size_bytes ?? 0)} · available until ${formatDate(report.expires_at, auth.user.timezone)}`}
                                            {report.status === 'expired' &&
                                                ' · no longer available'}
                                        </span>
                                        {report.status === 'failed' && (
                                            <span className="block text-xs text-red-700 dark:text-red-400">
                                                {report.error ??
                                                    'The report could not be made.'}
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        {(report.status === 'pending' ||
                                            report.status === 'processing') && (
                                            <span
                                                role="status"
                                                className="flex items-center gap-2 text-sm text-muted-foreground"
                                            >
                                                <Loader2
                                                    className="size-4 motion-safe:animate-spin"
                                                    aria-hidden="true"
                                                />
                                                Being prepared…
                                            </span>
                                        )}
                                        {report.download_url && (
                                            // A plain link: the browser saves the file.
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <a href={report.download_url}>
                                                    <Download aria-hidden="true" />
                                                    Download
                                                </a>
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground"
                                            aria-label={`Remove “${report.title}”`}
                                            onClick={() =>
                                                router.delete(
                                                    destroy.url({
                                                        family: family.slug,
                                                        report: report.id,
                                                    }),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

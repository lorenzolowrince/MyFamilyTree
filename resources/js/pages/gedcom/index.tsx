import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { fileSize } from '@/components/media/upload-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/dates';
import {
    exportMethod as exportGedcom,
    show,
    store,
} from '@/routes/families/gedcom';
import { complete as exportComplete } from '@/routes/families/gedcom/export';
import type { FamilyRef } from '@/types';

export type GedcomImportStatus =
    | 'uploaded'
    | 'parsing'
    | 'preview_ready'
    | 'importing'
    | 'completed'
    | 'failed'
    | 'cancelled';

type ImportRow = {
    id: string;
    filename: string | null;
    status: GedcomImportStatus;
    uploaded_by: string | null;
    created_at: string | null;
    counts: { people: number } | null;
};

type Props = {
    family: FamilyRef;
    members: number;
    imports: ImportRow[];
    limits: { max_bytes: number; max_people: number };
    can: { export: boolean; import: boolean };
};

export const STATUS_LABELS: Record<GedcomImportStatus, string> = {
    uploaded: 'Waiting to be read',
    parsing: 'Being read',
    preview_ready: 'Waiting for your review',
    importing: 'Being imported',
    completed: 'Imported',
    failed: 'Could not be used',
    cancelled: 'Cancelled',
};

export default function GedcomIndex({
    family,
    members,
    imports,
    limits,
    can,
}: Props) {
    const { auth } = usePage().props;
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string>();
    const [uploading, setUploading] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        setError(undefined);
        setUploading(true);

        router.post(
            store.url(family.slug),
            { file },
            {
                forceFormData: true,
                onError: (errors) => setError(errors.file),
                onFinish: () => {
                    setUploading(false);

                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title={`${family.name} – GEDCOM`} />

            <div className="mx-auto grid w-full max-w-3xl gap-8 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Import and export
                    </h1>
                    <p className="text-muted-foreground">
                        GEDCOM is the file format genealogy programs use to
                        exchange family trees.
                    </p>
                </div>

                {can.export && (
                    <section
                        aria-labelledby="export-heading"
                        className="grid gap-3 rounded-xl border p-4"
                    >
                        <h2
                            id="export-heading"
                            className="text-base font-medium"
                        >
                            Export this tree
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            A GEDCOM 7 file of all {members.toLocaleString()}{' '}
                            {members === 1 ? 'person' : 'people'} and their
                            relationships. Photos, documents and the timeline
                            are not part of the file.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid content-start gap-2 rounded-lg border p-3">
                                <h3 className="text-sm font-medium">
                                    For sharing
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Living people, and records marked restricted
                                    or private, keep their place in the tree but
                                    appear as “Living” or “Private” with no
                                    names, dates or other details. Use this to
                                    send the tree to a relative or to a
                                    genealogy website.
                                </p>
                                <div>
                                    {/* A plain link: the browser saves the file. */}
                                    <Button asChild variant="outline">
                                        <a href={exportGedcom.url(family.slug)}>
                                            <Download aria-hidden="true" />
                                            Download for sharing
                                        </a>
                                    </Button>
                                </div>
                            </div>
                            <div className="grid content-start gap-2 rounded-lg border p-3">
                                <h3 className="text-sm font-medium">
                                    Complete backup
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    Everything, including living people and
                                    private records. For your own backup or for
                                    moving to another program. You are asked for
                                    your password first. Keep the file somewhere
                                    safe.
                                </p>
                                <div>
                                    <Button asChild variant="outline">
                                        <a
                                            href={exportComplete.url(
                                                family.slug,
                                            )}
                                        >
                                            <Download aria-hidden="true" />
                                            Download complete backup
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </section>
                )}

                {can.import && (
                    <section
                        aria-labelledby="import-heading"
                        className="grid gap-3 rounded-xl border p-4"
                    >
                        <h2
                            id="import-heading"
                            className="text-base font-medium"
                        >
                            Import a GEDCOM file
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            The file is checked first and you see what it
                            contains, with anyone who may already be in this
                            tree, before anything is added. Up to{' '}
                            {fileSize(limits.max_bytes)} and{' '}
                            {limits.max_people.toLocaleString()} people per
                            file.
                        </p>
                        <div>
                            <input
                                ref={input}
                                type="file"
                                accept=".ged,.gedcom,text/plain"
                                className="sr-only"
                                aria-label="GEDCOM file"
                                onChange={(event) =>
                                    upload(event.target.files?.[0])
                                }
                            />
                            <Button
                                type="button"
                                disabled={uploading}
                                onClick={() => input.current?.click()}
                            >
                                <Upload aria-hidden="true" />
                                {uploading ? 'Uploading…' : 'Choose a file'}
                            </Button>
                            <InputError message={error} className="mt-2" />
                        </div>

                        {imports.length > 0 && (
                            <div className="mt-2 grid gap-2">
                                <h3 className="text-sm font-medium">
                                    Recent imports
                                </h3>
                                <ul className="grid gap-2">
                                    {imports.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2"
                                        >
                                            <span className="min-w-0">
                                                <Link
                                                    href={show({
                                                        family: family.slug,
                                                        import: item.id,
                                                    })}
                                                    className="block truncate text-sm font-medium hover:underline"
                                                >
                                                    {item.filename ??
                                                        'GEDCOM file'}
                                                </Link>
                                                <span className="block text-xs text-muted-foreground">
                                                    {item.uploaded_by}
                                                    {item.created_at &&
                                                        ` · ${formatDateTime(item.created_at, auth.user.timezone)}`}
                                                    {item.counts &&
                                                        ` · ${item.counts.people.toLocaleString()} people`}
                                                </span>
                                            </span>
                                            <Badge
                                                variant={
                                                    item.status === 'completed'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {STATUS_LABELS[item.status]}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

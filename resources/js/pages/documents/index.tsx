import { Form, Head, Link, router } from '@inertiajs/react';
import { FileText, Upload } from 'lucide-react';
import { useState } from 'react';
import {
    MediaStatusNote,
    useRefreshWhileChecking,
} from '@/components/media/media-status';
import { UploadDialog } from '@/components/media/upload-dialog';
import { Pager } from '@/components/pager';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, show, store } from '@/routes/families/documents';
import type { DocumentItem, FamilyRef, Option, Paginated } from '@/types';

type Props = {
    family: FamilyRef;
    documents: Paginated<DocumentItem>;
    filters: {
        category: string | null;
        q: string;
        person: { id: string; name: string } | null;
    };
    limits: { max_bytes: number; max_files: number };
    options: { visibilities: Option[]; categories: Option[] };
    can: { upload: boolean };
};

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function DocumentsIndex({
    family,
    documents,
    filters,
    limits,
    options,
    can,
}: Props) {
    const [uploading, setUploading] = useState(false);
    const [category, setCategory] = useState(filters.category ?? 'other');
    const [visibility, setVisibility] = useState('family');
    const [downloadable, setDownloadable] = useState(true);

    useRefreshWhileChecking(
        documents.data.some((document) => document.status === 'quarantined'),
        ['documents'],
    );

    return (
        <>
            <Head title={`${family.name} – Documents`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Documents
                        </h1>
                        <p className="text-muted-foreground">
                            {filters.person
                                ? `Documents about ${filters.person.name}.`
                                : 'Certificates, letters and records kept by the family.'}
                        </p>
                    </div>
                    {can.upload && (
                        <Button
                            type="button"
                            onClick={() => setUploading(true)}
                        >
                            <Upload aria-hidden="true" />
                            Upload documents
                        </Button>
                    )}
                </div>

                <Form
                    {...index.form(family.slug)}
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-end gap-3"
                    role="search"
                    aria-label="Find documents"
                >
                    {filters.person && (
                        <input
                            type="hidden"
                            name="person"
                            value={filters.person.id}
                        />
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="filter-q">Search</Label>
                        <Input
                            id="filter-q"
                            name="q"
                            type="search"
                            className="w-56"
                            defaultValue={filters.q}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="filter-category">Kind</Label>
                        <select
                            id="filter-category"
                            name="category"
                            defaultValue={filters.category ?? ''}
                            className={`${fieldClass} w-52`}
                        >
                            <option value="">All kinds</option>
                            {options.categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit" variant="outline">
                        Apply
                    </Button>
                    {(filters.category || filters.q || filters.person) && (
                        <Button variant="ghost" asChild>
                            <Link href={index(family.slug)}>Clear</Link>
                        </Button>
                    )}
                    <p
                        className="ml-auto text-sm text-muted-foreground"
                        role="status"
                    >
                        {documents.total.toLocaleString()}{' '}
                        {documents.total === 1 ? 'document' : 'documents'}
                    </p>
                </Form>

                {documents.data.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <FileText
                            className="size-8 text-muted-foreground/60"
                            aria-hidden="true"
                        />
                        <p className="max-w-md text-sm text-muted-foreground">
                            No documents here yet.
                            {can.upload &&
                                ' Upload PDFs or scans of certificates, letters and land or religious records.'}
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {documents.data.map((document) => (
                            <li
                                key={document.id}
                                className="flex gap-3 rounded-xl border p-3"
                            >
                                <div className="size-20 shrink-0 overflow-hidden rounded-md border bg-muted">
                                    {document.urls ? (
                                        <img
                                            src={document.urls.thumb}
                                            alt=""
                                            loading="lazy"
                                            className="size-full object-cover object-top"
                                        />
                                    ) : (
                                        <FileText
                                            className="m-6 size-8 text-muted-foreground/60"
                                            aria-hidden="true"
                                        />
                                    )}
                                </div>
                                <div className="grid min-w-0 flex-1 content-start gap-1">
                                    {document.urls ? (
                                        <Link
                                            href={show({
                                                family: family.slug,
                                                document: document.id,
                                            })}
                                            className="truncate text-sm font-medium hover:underline"
                                        >
                                            {document.title}
                                        </Link>
                                    ) : (
                                        <p className="truncate text-sm font-medium">
                                            {document.title}
                                        </p>
                                    )}
                                    <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <Badge variant="secondary">
                                            {document.category_label}
                                        </Badge>
                                        {document.issued && (
                                            <span>{document.issued}</span>
                                        )}
                                        {document.page_count !== null && (
                                            <span>
                                                {document.page_count}{' '}
                                                {document.page_count === 1
                                                    ? 'page'
                                                    : 'pages'}
                                            </span>
                                        )}
                                        {document.visibility !== 'family' && (
                                            <Badge variant="outline">
                                                {document.visibility_label}
                                            </Badge>
                                        )}
                                    </p>
                                    {document.status !== 'ready' && (
                                        <div className="mt-1 overflow-hidden rounded-md">
                                            <MediaStatusNote
                                                status={
                                                    document.status ===
                                                    'rejected'
                                                        ? 'rejected'
                                                        : 'quarantined'
                                                }
                                                reason={
                                                    document.rejection_reason
                                                }
                                                name={null}
                                            />
                                        </div>
                                    )}
                                    {document.status === 'rejected' && (
                                        <div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        destroy.url({
                                                            family: family.slug,
                                                            document:
                                                                document.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Dismiss
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Pager page={documents} />
            </div>

            {uploading && (
                <UploadDialog
                    title="Upload documents"
                    description="PDF files, or scans as JPEG, PNG, WebP, HEIC or TIFF. Each file is checked for viruses before anyone can open it."
                    action={store.url(family.slug)}
                    accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif,image/tiff,.heic,.heif,.tif,.tiff"
                    maxBytes={limits.max_bytes}
                    maxFiles={limits.max_files}
                    extra={{ category, visibility, downloadable }}
                    noun={['document', 'documents']}
                    onClose={() => setUploading(false)}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="upload-category">Kind</Label>
                            <select
                                id="upload-category"
                                className={fieldClass}
                                value={category}
                                onChange={(event) =>
                                    setCategory(event.target.value)
                                }
                            >
                                {options.categories.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {options.visibilities.length > 1 && (
                            <div className="grid gap-2">
                                <Label htmlFor="upload-visibility">
                                    Who can see them
                                </Label>
                                <select
                                    id="upload-visibility"
                                    className={fieldClass}
                                    value={visibility}
                                    onChange={(event) =>
                                        setVisibility(event.target.value)
                                    }
                                >
                                    {options.visibilities.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 rounded border-input"
                            checked={downloadable}
                            onChange={(event) =>
                                setDownloadable(event.target.checked)
                            }
                        />
                        Viewers and contributors may download these (otherwise
                        they can only read them on screen)
                    </label>
                </UploadDialog>
            )}
        </>
    );
}

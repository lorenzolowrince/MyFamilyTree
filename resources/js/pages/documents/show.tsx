import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { MediaStatusNote } from '@/components/media/media-status';
import { TimelineLinks } from '@/components/media/timeline-links';
import { fileSize } from '@/components/media/upload-dialog';
import { FuzzyDateInput } from '@/components/members/fuzzy-date-input';
import { MemberAvatar } from '@/components/members/member-avatar';
import { MemberMultiPicker } from '@/components/members/member-multi-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/dates';
import { destroy, index, update } from '@/routes/families/documents';
import { show as showMember } from '@/routes/families/members';
import type {
    DocumentItem,
    FamilyRef,
    FuzzyDateValue,
    MemberSummary,
    Option,
    TimelineLinks as Links,
} from '@/types';

type Document = DocumentItem & { people: MemberSummary[] };

type Props = {
    family: FamilyRef;
    document: Document;
    timeline: Links;
    options: {
        visibilities: Option[];
        qualifiers: Option[];
        categories: Option[];
    };
};

const fieldClass =
    'w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type EditForm = {
    title: string;
    description: string;
    category: string;
    issued: FuzzyDateValue;
    visibility: string;
    downloadable: boolean;
    member_ids: string[];
};

function EditDialog({
    family,
    document,
    options,
    onClose,
}: Pick<Props, 'family' | 'document' | 'options'> & { onClose: () => void }) {
    const [people, setPeople] = useState<MemberSummary[]>(document.people);
    const form = useForm<EditForm>({
        title: document.title ?? '',
        description: document.description ?? '',
        category: document.category,
        issued: document.issued_input,
        visibility: document.visibility,
        downloadable: document.downloadable,
        member_ids: [],
    });
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            member_ids: people.map((person) => person.id),
        }));
        form.patch(update.url({ family: family.slug, document: document.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Edit document</DialogTitle>
                    <DialogDescription>
                        Give it a clear title and say who it is about, so
                        relatives can find it.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="document-title">Title</Label>
                        <Input
                            id="document-title"
                            value={form.data.title}
                            maxLength={300}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="document-category">Kind</Label>
                            <select
                                id="document-category"
                                className={`${fieldClass} h-9`}
                                value={form.data.category}
                                onChange={(event) =>
                                    form.setData('category', event.target.value)
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
                                <Label htmlFor="document-visibility">
                                    Who can see it
                                </Label>
                                <select
                                    id="document-visibility"
                                    className={`${fieldClass} h-9`}
                                    value={form.data.visibility}
                                    onChange={(event) =>
                                        form.setData(
                                            'visibility',
                                            event.target.value,
                                        )
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

                    <FuzzyDateInput
                        label="Date of the document"
                        value={form.data.issued}
                        onChange={(value) => form.setData('issued', value)}
                        errors={errors}
                        errorPrefix="issued"
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="document-description">
                            Notes (optional)
                        </Label>
                        <textarea
                            id="document-description"
                            rows={4}
                            maxLength={5000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            className={`${fieldClass} py-2`}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 rounded border-input"
                            checked={form.data.downloadable}
                            onChange={(event) =>
                                form.setData(
                                    'downloadable',
                                    event.target.checked,
                                )
                            }
                        />
                        Viewers and contributors may download it (otherwise they
                        can only read it on screen)
                    </label>

                    <MemberMultiPicker
                        family={family}
                        label="Who it is about"
                        value={people}
                        onChange={setPeople}
                    />

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function DocumentShow({
    family,
    document,
    timeline,
    options,
}: Props) {
    const { auth } = usePage().props;
    const [editing, setEditing] = useState(false);
    const title = document.title ?? 'Document';
    const pages = document.urls?.pages ?? [];
    const notShown = (document.page_count ?? pages.length) - pages.length;

    return (
        <>
            <Head title={`${title} – ${family.name}`} />

            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index(family.slug)}>
                            <ArrowLeft aria-hidden="true" />
                            Documents
                        </Link>
                    </Button>
                    <div className="flex flex-wrap gap-2">
                        {document.urls?.download && (
                            // A plain link: the browser saves the file instead of opening it here.
                            <Button variant="outline" asChild>
                                <a href={document.urls.download}>
                                    <Download aria-hidden="true" />
                                    Download
                                </a>
                            </Button>
                        )}
                        {document.can.update && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil aria-hidden="true" />
                                Edit
                            </Button>
                        )}
                        {document.can.delete && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 dark:text-red-400"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Remove “${title}” from the family's documents?`,
                                        )
                                    ) {
                                        router.delete(
                                            destroy.url({
                                                family: family.slug,
                                                document: document.id,
                                            }),
                                        );
                                    }
                                }}
                            >
                                <Trash2 aria-hidden="true" />
                                Remove
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid content-start gap-4">
                        {document.urls ? (
                            pages.map((page, number) => (
                                <img
                                    key={page}
                                    src={page}
                                    alt={`${title}, page ${number + 1}`}
                                    loading={number === 0 ? 'eager' : 'lazy'}
                                    className="w-full rounded-lg border bg-white"
                                />
                            ))
                        ) : (
                            <div className="h-64 overflow-hidden rounded-xl border">
                                <MediaStatusNote
                                    status={
                                        document.status === 'rejected'
                                            ? 'rejected'
                                            : 'quarantined'
                                    }
                                    reason={document.rejection_reason}
                                    name={document.filename}
                                />
                            </div>
                        )}
                        {notShown > 0 && (
                            <p className="text-sm text-muted-foreground">
                                The first {pages.length} pages are shown.{' '}
                                {document.urls?.download
                                    ? `Download the file to read the other ${notShown}.`
                                    : `The other ${notShown} can be read by downloading the file, which this document does not allow for your role.`}
                            </p>
                        )}
                    </div>

                    <aside className="grid content-start gap-6">
                        <div className="grid gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <div className="flex flex-wrap gap-2">
                                <Badge variant="secondary">
                                    {document.category_label}
                                </Badge>
                                {document.issued && (
                                    <Badge variant="secondary">
                                        {document.issued}
                                    </Badge>
                                )}
                                {document.visibility !== 'family' && (
                                    <Badge variant="outline">
                                        {document.visibility_label}
                                    </Badge>
                                )}
                                {!document.downloadable && (
                                    <Badge variant="outline">View only</Badge>
                                )}
                            </div>
                            {document.description && (
                                <p className="text-sm whitespace-pre-line">
                                    {document.description}
                                </p>
                            )}
                        </div>

                        <section
                            aria-labelledby="people-heading"
                            className="grid gap-2"
                        >
                            <h2
                                id="people-heading"
                                className="text-sm font-medium"
                            >
                                About
                            </h2>
                            {document.people.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Not linked to anyone yet.
                                </p>
                            ) : (
                                <ul className="grid gap-2">
                                    {document.people.map((person) => (
                                        <li
                                            key={person.id}
                                            className="flex items-center gap-2.5"
                                        >
                                            <MemberAvatar
                                                member={person}
                                                className="size-8"
                                            />
                                            <Link
                                                href={showMember({
                                                    family: family.slug,
                                                    member: person.id,
                                                })}
                                                className="min-w-0 flex-1 truncate text-sm font-medium hover:underline"
                                            >
                                                {person.name}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <TimelineLinks
                            family={family}
                            links={timeline}
                            file={{ document_id: document.id }}
                        />

                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {document.page_count !== null && (
                                <>
                                    <dt>Pages</dt>
                                    <dd>{document.page_count}</dd>
                                </>
                            )}
                            {document.uploaded_by && (
                                <>
                                    <dt>Added by</dt>
                                    <dd>{document.uploaded_by}</dd>
                                </>
                            )}
                            {document.uploaded_at && (
                                <>
                                    <dt>Added on</dt>
                                    <dd>
                                        {formatDate(
                                            document.uploaded_at,
                                            auth.user.timezone,
                                        )}
                                    </dd>
                                </>
                            )}
                            <dt>Stored</dt>
                            <dd>{fileSize(document.size_bytes)}</dd>
                        </dl>
                    </aside>
                </div>
            </div>

            {editing && (
                <EditDialog
                    family={family}
                    document={document}
                    options={options}
                    onClose={() => setEditing(false)}
                />
            )}
        </>
    );
}

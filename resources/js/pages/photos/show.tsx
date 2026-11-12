import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react';
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
import { show as showMember } from '@/routes/families/members';
import {
    cover,
    destroy,
    index,
    profile,
    update,
} from '@/routes/families/photos';
import type {
    FamilyRef,
    FuzzyDateValue,
    MemberSummary,
    Option,
    PhotoItem,
    TimelineLinks as Links,
} from '@/types';

type TaggedPerson = MemberSummary & {
    is_profile_photo: boolean;
    can_set_profile: boolean;
};

type Photo = PhotoItem & {
    album: { id: string; title: string } | null;
    people: TaggedPerson[];
    is_family_cover: boolean;
};

type Props = {
    family: FamilyRef;
    photo: Photo;
    albums: { id: string; title: string }[];
    timeline: Links;
    options: { visibilities: Option[]; qualifiers: Option[] };
    can: { setCover: boolean };
};

const fieldClass =
    'w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type EditForm = {
    caption: string;
    description: string;
    taken: FuzzyDateValue;
    album_id: string | null;
    visibility: string;
    member_ids: string[];
};

function EditDialog({
    family,
    photo,
    albums,
    options,
    onClose,
}: Pick<Props, 'family' | 'photo' | 'albums' | 'options'> & {
    onClose: () => void;
}) {
    const [people, setPeople] = useState<MemberSummary[]>(photo.people);
    const form = useForm<EditForm>({
        caption: photo.caption ?? '',
        description: photo.description ?? '',
        taken: photo.taken_input,
        album_id: photo.album_id,
        visibility: photo.visibility,
        member_ids: [],
    });
    const errors = form.errors as Partial<Record<string, string>>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            member_ids: people.map((person) => person.id),
        }));
        form.patch(update.url({ family: family.slug, photo: photo.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Edit photo</DialogTitle>
                    <DialogDescription>
                        Say what the photo shows, when it was taken and who is
                        in it.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="photo-caption">Caption</Label>
                        <Input
                            id="photo-caption"
                            value={form.data.caption}
                            maxLength={300}
                            onChange={(event) =>
                                form.setData('caption', event.target.value)
                            }
                        />
                        <InputError message={errors.caption} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="photo-description">
                            Story (optional)
                        </Label>
                        <textarea
                            id="photo-description"
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

                    <FuzzyDateInput
                        label="When it was taken"
                        value={form.data.taken}
                        onChange={(value) => form.setData('taken', value)}
                        errors={errors}
                        errorPrefix="taken"
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="photo-album">Album</Label>
                            <select
                                id="photo-album"
                                className={`${fieldClass} h-9`}
                                value={form.data.album_id ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'album_id',
                                        event.target.value === ''
                                            ? null
                                            : event.target.value,
                                    )
                                }
                            >
                                <option value="">No album</option>
                                {albums.map((album) => (
                                    <option key={album.id} value={album.id}>
                                        {album.title}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.album_id} />
                        </div>
                        {options.visibilities.length > 1 && (
                            <div className="grid gap-2">
                                <Label htmlFor="photo-visibility">
                                    Who can see it
                                </Label>
                                <select
                                    id="photo-visibility"
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

                    <MemberMultiPicker
                        family={family}
                        label="Who is in the photo"
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

export default function PhotoShow({
    family,
    photo,
    albums,
    timeline,
    options,
    can,
}: Props) {
    const { auth, errors } = usePage().props;
    const [editing, setEditing] = useState(false);
    const title = photo.caption ?? photo.filename ?? 'Photo';

    const setProfile = (person: TaggedPerson, remove: boolean) =>
        router.post(
            profile.url({ family: family.slug, photo: photo.id }),
            { member_id: person.id, remove },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={`${title} – ${family.name}`} />

            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={
                                photo.album
                                    ? index.url(family.slug, {
                                          query: { album: photo.album.id },
                                      })
                                    : index(family.slug)
                            }
                        >
                            <ArrowLeft aria-hidden="true" />
                            {photo.album ? photo.album.title : 'Photo gallery'}
                        </Link>
                    </Button>
                    <div className="flex flex-wrap gap-2">
                        {photo.can.update && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil aria-hidden="true" />
                                Edit
                            </Button>
                        )}
                        {photo.can.delete && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 dark:text-red-400"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Remove this photo from the gallery?',
                                        )
                                    ) {
                                        router.delete(
                                            destroy.url({
                                                family: family.slug,
                                                photo: photo.id,
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
                    <figure className="grid gap-2">
                        <div className="flex min-h-64 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                            {photo.urls ? (
                                <a
                                    href={photo.urls.original}
                                    target="_blank"
                                    rel="noreferrer"
                                    aria-label="Open the full-size photo in a new tab"
                                >
                                    <img
                                        src={photo.urls.display}
                                        alt={title}
                                        width={photo.width ?? undefined}
                                        height={photo.height ?? undefined}
                                        className="max-h-[75dvh] w-auto max-w-full object-contain"
                                    />
                                </a>
                            ) : (
                                <div className="h-64 w-full">
                                    <MediaStatusNote
                                        status={
                                            photo.status === 'rejected'
                                                ? 'rejected'
                                                : 'quarantined'
                                        }
                                        reason={photo.rejection_reason}
                                        name={photo.filename}
                                    />
                                </div>
                            )}
                        </div>
                        {photo.caption && (
                            <figcaption className="text-sm text-muted-foreground">
                                {photo.caption}
                            </figcaption>
                        )}
                    </figure>

                    <aside className="grid content-start gap-6">
                        <div className="grid gap-2">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <div className="flex flex-wrap gap-2">
                                {photo.taken && (
                                    <Badge variant="secondary">
                                        {photo.taken}
                                    </Badge>
                                )}
                                {photo.visibility !== 'family' && (
                                    <Badge variant="outline">
                                        {photo.visibility_label}
                                    </Badge>
                                )}
                                {photo.is_family_cover && (
                                    <Badge variant="outline">
                                        Family cover
                                    </Badge>
                                )}
                            </div>
                            {photo.description && (
                                <p className="text-sm whitespace-pre-line">
                                    {photo.description}
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
                                In this photo
                            </h2>
                            {photo.people.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nobody has been tagged yet.
                                </p>
                            ) : (
                                <ul className="grid gap-2">
                                    {photo.people.map((person) => (
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
                                            {person.can_set_profile && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setProfile(
                                                            person,
                                                            person.is_profile_photo,
                                                        )
                                                    }
                                                >
                                                    {person.is_profile_photo
                                                        ? 'Stop using as profile picture'
                                                        : 'Use as profile picture'}
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <TimelineLinks
                            family={family}
                            links={timeline}
                            file={{ photo_id: photo.id }}
                        />

                        {can.setCover && !photo.is_family_cover && (
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            cover.url({
                                                family: family.slug,
                                                photo: photo.id,
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Use as the family&apos;s cover image
                                </Button>
                                <InputError
                                    message={errors.cover}
                                    className="mt-2"
                                />
                            </div>
                        )}

                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {photo.uploaded_by && (
                                <>
                                    <dt>Added by</dt>
                                    <dd>{photo.uploaded_by}</dd>
                                </>
                            )}
                            {photo.uploaded_at && (
                                <>
                                    <dt>Added on</dt>
                                    <dd>
                                        {formatDate(
                                            photo.uploaded_at,
                                            auth.user.timezone,
                                        )}
                                    </dd>
                                </>
                            )}
                            {photo.width && photo.height && (
                                <>
                                    <dt>Size</dt>
                                    <dd>
                                        {photo.width} × {photo.height} pixels,{' '}
                                        {fileSize(photo.size_bytes)} stored
                                    </dd>
                                </>
                            )}
                        </dl>
                    </aside>
                </div>
            </div>

            {editing && (
                <EditDialog
                    family={family}
                    photo={photo}
                    albums={albums}
                    options={options}
                    onClose={() => setEditing(false)}
                />
            )}
        </>
    );
}

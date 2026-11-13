import { Head, Link, router, useForm } from '@inertiajs/react';
import { FolderPlus, Images, Pencil, Upload } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import {
    MediaStatusNote,
    useRefreshWhileChecking,
} from '@/components/media/media-status';
import { UploadDialog } from '@/components/media/upload-dialog';
import { Pager } from '@/components/pager';
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
import { cn } from '@/lib/utils';
import {
    destroy as destroyAlbum,
    store as storeAlbum,
    update as updateAlbum,
} from '@/routes/families/albums';
import {
    destroy as destroyPhoto,
    index,
    show,
    store,
} from '@/routes/families/photos';
import type {
    AlbumItem,
    FamilyRef,
    Option,
    Paginated,
    PhotoItem,
} from '@/types';

type Props = {
    family: FamilyRef;
    photos: Paginated<PhotoItem>;
    albums: AlbumItem[];
    filters: {
        album: string | null;
        year: number | null;
        person: { id: string; name: string } | null;
    };
    years: number[];
    limits: { max_bytes: number; max_files: number };
    options: { visibilities: Option[] };
    can: { upload: boolean };
};

const fieldClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function AlbumDialog({
    family,
    album,
    onClose,
}: {
    family: FamilyRef;
    /** The album being renamed, or null for a new one. */
    album: AlbumItem | null;
    onClose: () => void;
}) {
    const form = useForm({
        title: album?.title ?? '',
        description: album?.description ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const settings = { preserveScroll: true, onSuccess: onClose };

        if (album) {
            form.patch(
                updateAlbum.url({ family: family.slug, album: album.id }),
                settings,
            );
        } else {
            form.post(storeAlbum.url(family.slug), settings);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {album ? 'Edit album' : 'New album'}
                    </DialogTitle>
                    <DialogDescription>
                        An album groups photos, for example of one reunion or
                        one branch of the family.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="album-title">Name</Label>
                        <Input
                            id="album-title"
                            value={form.data.title}
                            maxLength={150}
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="album-description">
                            Description (optional)
                        </Label>
                        <textarea
                            id="album-description"
                            rows={3}
                            maxLength={2000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            className={cn(fieldClass, 'h-auto py-2')}
                        />
                    </div>
                    <DialogFooter className="sm:justify-between">
                        {album ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600 dark:text-red-400"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Remove the album “${album.title}”? Its photos stay in the gallery.`,
                                        )
                                    ) {
                                        router.delete(
                                            destroyAlbum.url({
                                                family: family.slug,
                                                album: album.id,
                                            }),
                                        );
                                    }
                                }}
                            >
                                Remove album
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </span>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PhotosIndex({
    family,
    photos,
    albums,
    filters,
    years,
    limits,
    options,
    can,
}: Props) {
    const [uploading, setUploading] = useState(false);
    const [albumDialog, setAlbumDialog] = useState<AlbumItem | 'new' | null>(
        null,
    );
    const currentAlbum = albums.find((album) => album.id === filters.album);
    const [uploadAlbum, setUploadAlbum] = useState(currentAlbum?.id ?? '');
    const [uploadVisibility, setUploadVisibility] = useState('family');

    useRefreshWhileChecking(
        photos.data.some((photo) => photo.status === 'quarantined'),
        ['photos', 'albums', 'years'],
    );

    const query = (
        change: Partial<{ album: string | null; year: number | null }>,
    ) => {
        const next = { ...filters, ...change };

        return index.url(family.slug, {
            query: {
                album: next.album ?? undefined,
                year: next.year ?? undefined,
                person: filters.person?.id,
            },
        });
    };

    const tabClass = (active: boolean) =>
        cn(
            'flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/60',
            active
                ? 'border-primary bg-primary text-primary-foreground'
                : 'hover:bg-muted',
        );

    return (
        <>
            <Head title={`${family.name} – Photos`} />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Photo gallery
                        </h1>
                        <p className="text-muted-foreground">
                            {filters.person
                                ? `Photos of ${filters.person.name}.`
                                : family.name}
                        </p>
                    </div>
                    {can.upload && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAlbumDialog('new')}
                            >
                                <FolderPlus aria-hidden="true" />
                                New album
                            </Button>
                            <Button
                                type="button"
                                onClick={() => setUploading(true)}
                            >
                                <Upload aria-hidden="true" />
                                Upload photos
                            </Button>
                        </div>
                    )}
                </div>

                <nav
                    aria-label="Albums"
                    className="-mx-1 flex gap-2 overflow-x-auto px-1 py-1"
                >
                    <Link
                        href={query({ album: null })}
                        className={tabClass(filters.album === null)}
                        aria-current={
                            filters.album === null ? 'page' : undefined
                        }
                    >
                        All photos
                    </Link>
                    {albums.map((album) => (
                        <Link
                            key={album.id}
                            href={query({ album: album.id })}
                            className={tabClass(filters.album === album.id)}
                            aria-current={
                                filters.album === album.id ? 'page' : undefined
                            }
                        >
                            {album.cover_url && (
                                <img
                                    src={album.cover_url}
                                    alt=""
                                    className="size-5 rounded-full object-cover"
                                />
                            )}
                            {album.title}
                            <span className="tabular-nums opacity-70">
                                {album.count}
                            </span>
                        </Link>
                    ))}
                    {albums.length > 0 && (
                        <Link
                            href={query({ album: 'none' })}
                            className={tabClass(filters.album === 'none')}
                            aria-current={
                                filters.album === 'none' ? 'page' : undefined
                            }
                        >
                            Not in an album
                        </Link>
                    )}
                </nav>

                <div className="flex flex-wrap items-center gap-3">
                    {years.length > 0 && (
                        <label className="flex items-center gap-2 text-sm">
                            Year taken
                            <select
                                className={cn(fieldClass, 'w-32')}
                                value={filters.year ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        query({
                                            year:
                                                event.target.value === ''
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        }),
                                    )
                                }
                            >
                                <option value="">Any</option>
                                {years.map((year) => (
                                    <option key={year} value={year}>
                                        {year}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {filters.person && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index(family.slug)}>
                                Show everyone&apos;s photos
                            </Link>
                        </Button>
                    )}
                    {currentAlbum?.can.update && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setAlbumDialog(currentAlbum)}
                        >
                            <Pencil aria-hidden="true" />
                            Edit album
                        </Button>
                    )}
                    <p
                        className="ml-auto text-sm text-muted-foreground"
                        role="status"
                    >
                        {photos.total.toLocaleString()}{' '}
                        {photos.total === 1 ? 'photo' : 'photos'}
                    </p>
                </div>

                {currentAlbum?.description && (
                    <p className="text-sm whitespace-pre-line text-muted-foreground">
                        {currentAlbum.description}
                    </p>
                )}

                {photos.data.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <Images
                            className="size-8 text-muted-foreground/60"
                            aria-hidden="true"
                        />
                        <p className="max-w-md text-sm text-muted-foreground">
                            No photos here yet.
                            {can.upload &&
                                ' Upload some: location data is removed from every photo, and only members of this family can see them.'}
                        </p>
                    </div>
                ) : (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        {photos.data.map((photo) => (
                            <li
                                key={photo.id}
                                className="relative aspect-square overflow-hidden rounded-lg border bg-muted"
                            >
                                {photo.urls ? (
                                    <Link
                                        href={show({
                                            family: family.slug,
                                            photo: photo.id,
                                        })}
                                        className="group block size-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/60"
                                    >
                                        <img
                                            src={photo.urls.thumb}
                                            alt={
                                                photo.caption ??
                                                `Photo${photo.taken ? ` from ${photo.taken}` : ''}`
                                            }
                                            loading="lazy"
                                            className="size-full object-cover transition-transform group-hover:scale-105 motion-reduce:transition-none"
                                        />
                                        {photo.caption && (
                                            <span className="absolute inset-x-0 bottom-0 truncate bg-black/60 px-2 py-1 text-xs text-white">
                                                {photo.caption}
                                            </span>
                                        )}
                                    </Link>
                                ) : (
                                    <>
                                        <MediaStatusNote
                                            status={
                                                photo.status === 'rejected'
                                                    ? 'rejected'
                                                    : 'quarantined'
                                            }
                                            reason={photo.rejection_reason}
                                            name={photo.filename}
                                        />
                                        {photo.status === 'rejected' && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="absolute inset-x-2 bottom-2"
                                                onClick={() =>
                                                    router.delete(
                                                        destroyPhoto.url({
                                                            family: family.slug,
                                                            photo: photo.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Dismiss
                                            </Button>
                                        )}
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <Pager page={photos} />
            </div>

            {uploading && (
                <UploadDialog
                    title="Upload photos"
                    description="JPEG, PNG, WebP or HEIC. Each photo is checked for viruses and its location data is removed before anyone can see it."
                    action={store.url(family.slug)}
                    accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
                    maxBytes={limits.max_bytes}
                    maxFiles={limits.max_files}
                    extra={{
                        album_id: uploadAlbum === '' ? null : uploadAlbum,
                        visibility: uploadVisibility,
                    }}
                    noun={['photo', 'photos']}
                    onClose={() => setUploading(false)}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {albums.length > 0 && (
                            <div className="grid gap-2">
                                <Label htmlFor="upload-album">Album</Label>
                                <select
                                    id="upload-album"
                                    className={fieldClass}
                                    value={uploadAlbum}
                                    onChange={(event) =>
                                        setUploadAlbum(event.target.value)
                                    }
                                >
                                    <option value="">No album</option>
                                    {albums.map((album) => (
                                        <option key={album.id} value={album.id}>
                                            {album.title}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {options.visibilities.length > 1 && (
                            <div className="grid gap-2">
                                <Label htmlFor="upload-visibility">
                                    Who can see them
                                </Label>
                                <select
                                    id="upload-visibility"
                                    className={fieldClass}
                                    value={uploadVisibility}
                                    onChange={(event) =>
                                        setUploadVisibility(event.target.value)
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
                </UploadDialog>
            )}

            {albumDialog && (
                <AlbumDialog
                    key={albumDialog === 'new' ? 'new' : albumDialog.id}
                    family={family}
                    album={albumDialog === 'new' ? null : albumDialog}
                    onClose={() => setAlbumDialog(null)}
                />
            )}
        </>
    );
}

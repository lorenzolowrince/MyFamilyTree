import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { tree, update } from '@/routes/families';
import {
    destroy as destroyCover,
    store as storeCover,
} from '@/routes/families/cover';

type Props = {
    family: {
        slug: string;
        name: string;
        description: string | null;
        cover_url: string | null;
        editors_need_approval: boolean;
        contributors_publish_directly: boolean;
    };
};

export default function FamilySettings({ family }: Props) {
    const form = useForm({
        name: family.name,
        description: family.description ?? '',
        editors_need_approval: family.editors_need_approval,
        contributors_publish_directly: family.contributors_publish_directly,
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const [coverError, setCoverError] = useState<string>();
    const [uploading, setUploading] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(update.url(family.slug), { preserveScroll: true });
    };

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        setCoverError(undefined);
        setUploading(true);

        router.post(
            storeCover.url(family.slug),
            { cover: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => setCoverError(errors.cover),
                onFinish: () => {
                    setUploading(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title={`${family.name} – Settings`} />

            <div className="mx-auto grid w-full max-w-2xl gap-10 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Family settings
                    </h1>
                    <p className="text-muted-foreground">
                        <Link
                            href={tree(family.slug)}
                            className="underline underline-offset-4"
                        >
                            Back to the tree
                        </Link>
                    </p>
                </div>

                <section aria-label="Family details">
                    <Heading
                        variant="small"
                        title="Details"
                        description="The name and description everyone in the family sees."
                    />
                    <form
                        onSubmit={submit}
                        className="mt-4 grid gap-5"
                        noValidate
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="name">Family name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                maxLength={120}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                rows={4}
                                maxLength={2000}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError message={form.errors.description} />
                        </div>
                        <fieldset className="grid gap-3 rounded-lg border p-4">
                            <legend className="px-1 text-sm font-medium">
                                Approval of changes
                            </legend>
                            <p className="text-sm text-muted-foreground">
                                By default, Editors&apos; changes apply at once
                                and Contributors&apos; changes wait for
                                approval.
                            </p>
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-4 rounded border-input"
                                    checked={form.data.editors_need_approval}
                                    onChange={(event) =>
                                        form.setData(
                                            'editors_need_approval',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Editors&apos; changes also need approval by the
                                owner or an administrator
                            </label>
                            <label className="flex items-start gap-3 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-4 rounded border-input"
                                    checked={
                                        form.data.contributors_publish_directly
                                    }
                                    onChange={(event) =>
                                        form.setData(
                                            'contributors_publish_directly',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Contributors may add and edit without approval
                            </label>
                        </fieldset>
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </div>
                    </form>
                </section>

                <section aria-labelledby="cover-heading">
                    <h2 id="cover-heading" className="text-base font-medium">
                        Cover image
                    </h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Shown on the family card. JPEG, PNG or WebP, up to 5 MB.
                        Only members of this family can see it.
                    </p>

                    {family.cover_url && (
                        <img
                            src={family.cover_url}
                            alt="Current cover"
                            className="mb-4 h-40 w-full rounded-xl border object-cover"
                        />
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={fileInput}
                            id="cover"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="sr-only"
                            onChange={(event) =>
                                upload(event.target.files?.[0])
                            }
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={uploading}
                            onClick={() => fileInput.current?.click()}
                        >
                            {uploading
                                ? 'Uploading…'
                                : family.cover_url
                                  ? 'Replace image'
                                  : 'Choose image'}
                        </Button>
                        {family.cover_url && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        destroyCover.url(family.slug),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                    <InputError message={coverError} className="mt-2" />
                </section>
            </div>
        </>
    );
}

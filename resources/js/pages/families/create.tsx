import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index, store } from '@/routes/families';

export default function FamilyCreate() {
    const form = useForm({ name: '', description: '', add_myself: true });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url());
    };

    return (
        <>
            <Head title="New family tree" />

            <div className="mx-auto w-full max-w-xl p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    New family tree
                </h1>
                <p className="mb-6 text-muted-foreground">
                    You will be the owner. You can invite relatives later.
                </p>

                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Family name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            maxLength={120}
                            autoFocus
                            placeholder="e.g. Keluarga Lidau"
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-describedby="name-hint"
                        />
                        <p
                            id="name-hint"
                            className="text-xs text-muted-foreground"
                        >
                            Shown to everyone you invite.
                        </p>
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">
                            Description (optional)
                        </Label>
                        <textarea
                            id="description"
                            rows={3}
                            maxLength={2000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 rounded border-input"
                            checked={form.data.add_myself}
                            onChange={(event) =>
                                form.setData('add_myself', event.target.checked)
                            }
                        />
                        <span>
                            <span className="font-medium">
                                Add me as the first person
                            </span>
                            <span className="block text-muted-foreground">
                                Puts you in the tree and links your account to
                                that person, so the tree opens centred on you.
                            </span>
                        </span>
                    </label>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={form.processing}>
                            Create family tree
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

FamilyCreate.layout = {
    breadcrumbs: [
        { title: 'Family trees', href: index() },
        { title: 'New', href: create() },
    ],
};

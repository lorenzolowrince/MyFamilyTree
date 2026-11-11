import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, store } from '@/routes/families/members/names';
import type { FamilyRef, MemberNameItem, Option } from '@/types';

/** Other names a person is or was known by; all of them are searchable. */
export function MemberNames({
    family,
    memberId,
    names,
    types,
    canEdit,
}: {
    family: FamilyRef;
    memberId: string;
    names: MemberNameItem[];
    types: Option[];
    canEdit: boolean;
}) {
    const form = useForm({ name: '', type: 'alias' });

    if (names.length === 0 && !canEdit) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url({ family: family.slug, member: memberId }), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <section aria-labelledby="names-heading">
            <h2 id="names-heading" className="mb-3 text-base font-medium">
                Other names
            </h2>

            {names.length === 0 ? (
                <p className="mb-3 text-sm text-muted-foreground">
                    None recorded. Add a birth name, married name or another
                    spelling so searching finds this person.
                </p>
            ) : (
                <ul className="mb-3 grid gap-2 sm:grid-cols-2">
                    {names.map((name) => (
                        <li
                            key={name.id}
                            className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2"
                        >
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-medium">
                                    {name.name}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {name.type_label}
                                </span>
                            </span>
                            {canEdit && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 text-muted-foreground"
                                    aria-label={`Remove the name ${name.name}`}
                                    onClick={() =>
                                        router.delete(
                                            destroy.url({
                                                family: family.slug,
                                                member: memberId,
                                                name: name.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canEdit && (
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-2"
                    noValidate
                >
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="other-name">Add a name</Label>
                        <Input
                            id="other-name"
                            value={form.data.name}
                            maxLength={200}
                            autoComplete="off"
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="other-name-type">Kind</Label>
                        <select
                            id="other-name-type"
                            value={form.data.type}
                            onChange={(event) =>
                                form.setData('type', event.target.value)
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing || form.data.name === ''}
                    >
                        Add
                    </Button>
                    <InputError
                        message={form.errors.name}
                        className="basis-full"
                    />
                </form>
            )}
        </section>
    );
}

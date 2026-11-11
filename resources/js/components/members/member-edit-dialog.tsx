import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { FuzzyDateInput } from '@/components/members/fuzzy-date-input';
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
import { update } from '@/routes/families/members';
import { emptyFuzzyDate } from '@/types/family';
import type {
    FamilyRef,
    FuzzyDateValue,
    GenealogyOptions,
    MemberProfile,
} from '@/types';

const fieldClass =
    'w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type FormData = {
    version: number;
    full_name: string;
    nickname: string;
    given_names: string;
    surname: string;
    maiden_name: string;
    gender: string;
    living_status: string;
    birth: FuzzyDateValue;
    birth_place: string;
    death: FuzzyDateValue;
    death_place: string;
    hometown: string;
    occupation: string;
    education: string;
    nationality: string;
    biography: string;
    notes: string;
    visibility: string;
};

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/** The full editor for one person. Render it only while open. */
export function MemberEditDialog({
    family,
    member,
    options,
    canEditRestricted,
    onClose,
}: {
    family: FamilyRef;
    member: MemberProfile;
    options: GenealogyOptions;
    /** Research notes and visibility are for editors and above. */
    canEditRestricted: boolean;
    onClose: () => void;
}) {
    const form = useForm<FormData>({
        version: member.version,
        full_name: member.name,
        nickname: member.nickname ?? '',
        given_names: member.given_names ?? '',
        surname: member.surname ?? '',
        maiden_name: member.maiden_name ?? '',
        gender: member.gender ?? '',
        living_status: member.living_status,
        birth: member.birth ?? emptyFuzzyDate,
        birth_place: member.birth_place ?? '',
        death: member.death,
        death_place: member.death_place ?? '',
        hometown: member.hometown ?? '',
        occupation: member.occupation ?? '',
        education: member.education ?? '',
        nationality: member.nationality ?? '',
        biography: member.biography ?? '',
        notes: member.notes ?? '',
        visibility: member.visibility ?? 'family',
    });

    const errors = form.errors as Partial<Record<string, string>>;

    const text = (
        field: Exclude<keyof FormData, 'birth' | 'death' | 'version'>,
        label: string,
        maxLength = 200,
    ) => (
        <Field id={`edit-${field}`} label={label} error={errors[field]}>
            <Input
                id={`edit-${field}`}
                value={form.data[field]}
                maxLength={maxLength}
                autoComplete="off"
                onChange={(event) => form.setData(field, event.target.value)}
            />
        </Field>
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            gender: data.gender || null,
            death: data.living_status === 'deceased' ? data.death : null,
            death_place:
                data.living_status === 'deceased' ? data.death_place : null,
        }));

        form.patch(update.url({ family: family.slug, member: member.id }), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Edit {member.name}</DialogTitle>
                    <DialogDescription>
                        Everything except the name is optional. Leave blank what
                        you do not know.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-5" noValidate>
                    {errors.version && (
                        <p
                            role="alert"
                            className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                        >
                            {errors.version}
                        </p>
                    )}

                    {text('full_name', 'Full name (as the family writes it)')}

                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('nickname', 'Known as', 100)}
                        {text('maiden_name', 'Maiden or birth name')}
                        {text('given_names', 'Given names (for sorting)')}
                        {text('surname', 'Surname or family name')}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="edit-gender"
                            label="Gender"
                            error={errors.gender}
                        >
                            <select
                                id="edit-gender"
                                className={`${fieldClass} h-9`}
                                value={form.data.gender}
                                onChange={(event) =>
                                    form.setData('gender', event.target.value)
                                }
                            >
                                <option value="">Not stated</option>
                                {options.genders.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field
                            id="edit-living"
                            label="Living?"
                            error={errors.living_status}
                        >
                            <select
                                id="edit-living"
                                className={`${fieldClass} h-9`}
                                value={form.data.living_status}
                                onChange={(event) =>
                                    form.setData(
                                        'living_status',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="unknown">Not sure</option>
                                <option value="living">Living</option>
                                <option value="deceased">Deceased</option>
                            </select>
                        </Field>
                    </div>

                    <FuzzyDateInput
                        label="Born"
                        value={form.data.birth}
                        onChange={(value) => form.setData('birth', value)}
                        errors={errors}
                        errorPrefix="birth"
                    />
                    {text('birth_place', 'Place of birth')}

                    {form.data.living_status === 'deceased' && (
                        <>
                            <FuzzyDateInput
                                label="Died"
                                value={form.data.death}
                                onChange={(value) =>
                                    form.setData('death', value)
                                }
                                errors={errors}
                                errorPrefix="death"
                            />
                            {text('death_place', 'Place of death')}
                        </>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('hometown', 'Hometown')}
                        {text('nationality', 'Nationality', 100)}
                        {text('occupation', 'Occupation')}
                        {text('education', 'Education')}
                    </div>

                    <Field
                        id="edit-biography"
                        label="Life story"
                        error={errors.biography}
                    >
                        <textarea
                            id="edit-biography"
                            rows={5}
                            maxLength={20000}
                            value={form.data.biography}
                            onChange={(event) =>
                                form.setData('biography', event.target.value)
                            }
                            className={`${fieldClass} py-2`}
                        />
                    </Field>

                    {canEditRestricted && (
                        <>
                            <Field
                                id="edit-notes"
                                label="Research notes (editors and above only)"
                                error={errors.notes}
                            >
                                <textarea
                                    id="edit-notes"
                                    rows={3}
                                    maxLength={10000}
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    className={`${fieldClass} py-2`}
                                />
                            </Field>
                            <Field
                                id="edit-visibility"
                                label="Who can see this person"
                                error={errors.visibility}
                            >
                                <select
                                    id="edit-visibility"
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
                            </Field>
                        </>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

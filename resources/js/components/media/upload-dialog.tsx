import { router } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export function fileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    if (bytes < 1024 ** 3) {
        return `${(bytes / 1024 / 1024).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
    }

    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

/**
 * Choose files and send them. The server decides what each file really is and checks it
 * before it appears; the limits here only save people a wasted upload.
 */
export function UploadDialog({
    title,
    description,
    action,
    accept,
    maxBytes,
    maxFiles,
    extra,
    children,
    onClose,
}: {
    title: string;
    description: string;
    /** Where the files are posted. */
    action: string;
    accept: string;
    maxBytes: number;
    maxFiles: number;
    /** Details sent with every file (album, visibility...). */
    extra: Record<string, string | boolean | string[] | null>;
    /** Fields for those details. */
    children?: ReactNode;
    onClose: () => void;
}) {
    const id = useId();
    const [files, setFiles] = useState<File[]>([]);
    const [problems, setProblems] = useState<string[]>([]);
    const [progress, setProgress] = useState<number | null>(null);

    const tooLarge = files.filter((file) => file.size > maxBytes);
    const blocked = files.length === 0 || files.length > maxFiles;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setProblems([]);

        const payload = Object.fromEntries(
            Object.entries(extra).filter(([, value]) => value !== null),
        );

        router.post(
            action,
            {
                ...payload,
                files: files.filter((file) => file.size <= maxBytes),
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onProgress: (event) => setProgress(event?.percentage ?? null),
                onError: (errors) =>
                    setProblems(
                        Object.values(errors).flatMap((message) =>
                            message.split('\n'),
                        ),
                    ),
                onSuccess: onClose,
                onFinish: () => {
                    setProgress(null);
                    setFiles([]);
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-files`}>
                            Files (up to {maxFiles}, {fileSize(maxBytes)} each)
                        </Label>
                        <input
                            id={`${id}-files`}
                            type="file"
                            multiple
                            accept={accept}
                            className="block w-full text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm file:font-medium"
                            onChange={(event) =>
                                setFiles([...(event.target.files ?? [])])
                            }
                        />
                        {files.length > 0 && (
                            <p
                                className="text-sm text-muted-foreground"
                                role="status"
                            >
                                {files.length}{' '}
                                {files.length === 1 ? 'file' : 'files'} chosen,{' '}
                                {fileSize(
                                    files.reduce(
                                        (total, file) => total + file.size,
                                        0,
                                    ),
                                )}
                            </p>
                        )}
                    </div>

                    {children}

                    {(tooLarge.length > 0 ||
                        files.length > maxFiles ||
                        problems.length > 0) && (
                        <ul
                            role="alert"
                            className="grid gap-1 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
                        >
                            {files.length > maxFiles && (
                                <li>
                                    Choose at most {maxFiles} files at a time.
                                </li>
                            )}
                            {tooLarge.map((file) => (
                                <li key={file.name}>
                                    {file.name} is larger than{' '}
                                    {fileSize(maxBytes)} and will be skipped.
                                </li>
                            ))}
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    )}

                    {progress !== null && (
                        <div
                            role="progressbar"
                            aria-label="Upload progress"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={progress}
                            className="h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className="h-full bg-primary transition-[width]"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={blocked || progress !== null}
                        >
                            <Upload aria-hidden="true" />
                            {progress === null ? 'Upload' : 'Uploading…'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

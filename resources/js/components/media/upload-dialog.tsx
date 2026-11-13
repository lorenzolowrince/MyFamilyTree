import { router } from '@inertiajs/react';
import { Camera, FolderOpen, Upload, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { toast } from 'sonner';
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
import { RETRIES, uploadAll } from '@/lib/upload-queue';
import type { UploadFields, UploadStatus } from '@/lib/upload-queue';

export function fileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    if (bytes < 1024 ** 3) {
        return `${(bytes / 1024 / 1024).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
    }

    return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
}

/** Phones and tablets: the camera button opens the camera directly. */
const touchDevice = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(pointer: coarse)').matches;

/**
 * Choose files (or take a photo) and send them. The server decides what each file really
 * is and checks it before it appears; the limits here only save people a wasted upload.
 *
 * Files are sent one at a time (lib/upload-queue.ts): very large photos are shrunk on the
 * device first, and a dropped connection is retried when the device is back online.
 */
export function UploadDialog({
    title,
    description,
    action,
    accept,
    maxBytes,
    maxFiles,
    extra,
    noun,
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
    extra: UploadFields;
    /** What is being uploaded, for messages: ['photo', 'photos']. */
    noun: [string, string];
    /** Fields for those details. */
    children?: ReactNode;
    onClose: () => void;
}) {
    const id = useId();
    const cameraInput = useRef<HTMLInputElement>(null);
    const filesInput = useRef<HTMLInputElement>(null);
    const [camera] = useState(touchDevice);
    const [files, setFiles] = useState<File[]>([]);
    const [problems, setProblems] = useState<string[]>([]);
    const [status, setStatus] = useState<UploadStatus | null>(null);

    const tooLarge = files.filter((file) => file.size > maxBytes);
    const sendable = files.filter((file) => file.size <= maxBytes);
    const busy = status !== null;
    const blocked = sendable.length === 0 || files.length > maxFiles || busy;

    // A photo from the camera has a generic name; make it recognisable.
    const add = (chosen: FileList | null, fromCamera = false) => {
        const added = [...(chosen ?? [])].map((file) =>
            fromCamera && /^image\.\w+$/i.test(file.name)
                ? new File(
                      [file],
                      `Photo ${new Date().toISOString().slice(0, 16).replace('T', ' ')}.${file.name.split('.').pop()}`,
                      { type: file.type, lastModified: file.lastModified },
                  )
                : file,
        );

        setFiles((current) => [...current, ...added]);
        setProblems([]);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setProblems([]);

        const report = await uploadAll(action, extra, sendable, setStatus);
        setStatus(null);

        if (report.stored > 0) {
            toast.success(
                report.stored === 1
                    ? `1 ${noun[0]} uploaded. It appears once it has been checked.`
                    : `${report.stored} ${noun[1]} uploaded. They appear once they have been checked.`,
            );
            router.reload();
        }

        const unsent =
            report.unsent.length > 0 && !report.stopped
                ? [
                      `${report.unsent.length} ${report.unsent.length === 1 ? 'file was' : 'files were'} not sent because the connection was lost. They are still listed: try again when you are back online.`,
                  ]
                : [];

        if (report.problems.length === 0 && unsent.length === 0) {
            onClose();

            return;
        }

        // Keep only what still needs sending, so "Upload" can simply be pressed again.
        setFiles(report.unsent);
        setProblems([...report.problems, ...unsent]);
    };

    const statusText = (current: UploadStatus): string => {
        const position = `${current.index + 1} of ${sendable.length}`;

        switch (current.phase) {
            case 'preparing':
                return `Preparing ${position}…`;
            case 'sending':
                return `Sending ${position}… ${current.percent}%`;
            case 'waiting':
                return `Connection lost. Trying ${position} again when you are back online (attempt ${current.attempt} of ${RETRIES})…`;
        }
    };

    const overall =
        status === null
            ? 0
            : Math.round(
                  ((status.index +
                      (status.phase === 'sending' ? status.percent / 100 : 0)) /
                      Math.max(1, sendable.length)) *
                      100,
              );

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                // Closing mid-upload would hide the progress; finish or fail first.
                if (!open && !busy) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={(event) => void submit(event)}
                    className="grid gap-4"
                    noValidate
                >
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-files`}>
                            Files (up to {maxFiles}, {fileSize(maxBytes)} each)
                        </Label>
                        {/* Native inputs stay hidden: the list below shows what was chosen. */}
                        <input
                            id={`${id}-files`}
                            ref={filesInput}
                            type="file"
                            multiple
                            accept={accept}
                            disabled={busy}
                            className="sr-only"
                            tabIndex={-1}
                            onChange={(event) => {
                                add(event.target.files);
                                event.target.value = '';
                            }}
                        />
                        {camera && (
                            <input
                                ref={cameraInput}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                className="sr-only"
                                tabIndex={-1}
                                aria-hidden="true"
                                onChange={(event) => {
                                    add(event.target.files, true);
                                    event.target.value = '';
                                }}
                            />
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => filesInput.current?.click()}
                            >
                                <FolderOpen aria-hidden="true" />
                                {files.length > 0
                                    ? 'Add more files'
                                    : 'Choose files'}
                            </Button>
                            {camera && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => cameraInput.current?.click()}
                                >
                                    <Camera aria-hidden="true" />
                                    Take a photo
                                </Button>
                            )}
                        </div>

                        {files.length > 0 && (
                            <>
                                <ul className="grid gap-1 text-sm">
                                    {files.map((file, index) => (
                                        <li
                                            key={`${file.name}-${file.lastModified}-${index}`}
                                            className="flex items-center justify-between gap-2 rounded-md border px-2 py-1"
                                        >
                                            <span className="min-w-0 truncate">
                                                {file.name}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-1 text-muted-foreground">
                                                {fileSize(file.size)}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7"
                                                    disabled={busy}
                                                    aria-label={`Remove ${file.name}`}
                                                    onClick={() =>
                                                        setFiles((current) =>
                                                            current.filter(
                                                                (_, at) =>
                                                                    at !==
                                                                    index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <X aria-hidden="true" />
                                                </Button>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-sm text-muted-foreground">
                                    {files.length}{' '}
                                    {files.length === 1 ? 'file' : 'files'},{' '}
                                    {fileSize(
                                        files.reduce(
                                            (total, file) => total + file.size,
                                            0,
                                        ),
                                    )}
                                    . Very large photos are made smaller on this
                                    device before sending.
                                </p>
                            </>
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
                                <li key={`${file.name}-${file.lastModified}`}>
                                    {file.name} is larger than{' '}
                                    {fileSize(maxBytes)} and will be skipped.
                                </li>
                            ))}
                            {problems.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    )}

                    {status !== null && (
                        <div className="grid gap-2">
                            <div
                                role="progressbar"
                                aria-label="Upload progress"
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={overall}
                                className="h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    className="h-full bg-primary transition-[width]"
                                    style={{ width: `${overall}%` }}
                                />
                            </div>
                            <p
                                className="text-sm text-muted-foreground"
                                role="status"
                            >
                                {statusText(status)}
                            </p>
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={blocked}>
                            <Upload aria-hidden="true" />
                            {busy ? 'Uploading…' : 'Upload'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Sends files one at a time, so a dropped connection costs at most the file being sent.
 * A file that fails because the connection dropped is sent again when the device is back
 * online (up to RETRIES times). Each file goes through the same server checks as before.
 */
import { shrinkForUpload } from '@/lib/image-shrink';

export const RETRIES = 5;

export type UploadFields = Record<string, string | boolean | string[] | null>;

export type UploadReport = {
    stored: number;
    problems: string[];
    /** Files that could not be sent at all (offline too long, session ended...). */
    unsent: File[];
    /** Sending stopped early for a reason already in `problems` (session ended, limit...). */
    stopped: boolean;
};

export type UploadStatus =
    | { phase: 'preparing'; index: number }
    | { phase: 'sending'; index: number; percent: number }
    | { phase: 'waiting'; index: number; attempt: number };

type Answer =
    | { kind: 'done'; stored: number; problems: string[] }
    | { kind: 'offline' }
    | { kind: 'stop'; message: string };

function xsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1] ?? '') : '';
}

function formFor(fields: UploadFields, file: File): FormData {
    const form = new FormData();

    for (const [key, value] of Object.entries(fields)) {
        if (value === null) {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => form.append(`${key}[]`, item));
        } else if (typeof value === 'boolean') {
            form.append(key, value ? '1' : '0');
        } else {
            form.append(key, value);
        }
    }

    form.append('files[]', file, file.name);

    return form;
}

function send(
    action: string,
    form: FormData,
    onProgress: (percent: number) => void,
): Promise<Answer> {
    return new Promise((resolve) => {
        const request = new XMLHttpRequest();
        request.open('POST', action);
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };
        request.onerror = () => resolve({ kind: 'offline' });
        request.ontimeout = () => resolve({ kind: 'offline' });
        request.onload = () => {
            let body: {
                stored?: number;
                problems?: string[];
                message?: string;
                errors?: Record<string, string[]>;
            } = {};

            try {
                body = JSON.parse(request.responseText) as typeof body;
            } catch {
                body = {};
            }

            switch (true) {
                case request.status === 200 || request.status === 422: {
                    // Our own answer lists problems; a validation error lists errors.
                    const problems =
                        body.problems ??
                        Object.values(body.errors ?? {}).flat();

                    resolve({
                        kind: 'done',
                        stored: body.stored ?? 0,
                        problems:
                            request.status === 422 && problems.length === 0
                                ? [body.message ?? 'The file was not accepted.']
                                : problems,
                    });
                    break;
                }
                case request.status === 401 || request.status === 419:
                    resolve({
                        kind: 'stop',
                        message:
                            'Your session has ended. Sign in again, then upload the remaining files.',
                    });
                    break;
                case request.status === 403 || request.status === 404:
                    resolve({
                        kind: 'stop',
                        message: 'You can no longer upload to this family.',
                    });
                    break;
                case request.status === 429:
                    resolve({
                        kind: 'stop',
                        message:
                            'Too many uploads in a short time. Wait a few minutes, then upload the remaining files.',
                    });
                    break;
                case request.status === 413:
                    resolve({
                        kind: 'done',
                        stored: 0,
                        problems: [
                            'A file was larger than the server accepts.',
                        ],
                    });
                    break;
                default:
                    // Server busy or restarting: treat like a dropped connection.
                    resolve({ kind: 'offline' });
            }
        };
        request.send(form);
    });
}

/** Resolves when the device is online again, or after `ms`, whichever is first. */
function backOnline(ms: number): Promise<void> {
    return new Promise((resolve) => {
        const done = () => {
            window.removeEventListener('online', done);
            clearTimeout(timer);
            resolve();
        };
        const timer = setTimeout(done, ms);

        window.addEventListener('online', done);
    });
}

export async function uploadAll(
    action: string,
    fields: UploadFields,
    files: File[],
    onStatus: (status: UploadStatus) => void,
): Promise<UploadReport> {
    const report: UploadReport = {
        stored: 0,
        problems: [],
        unsent: [],
        stopped: false,
    };

    for (let index = 0; index < files.length; index++) {
        onStatus({ phase: 'preparing', index });
        const file = await shrinkForUpload(files[index]);
        let answer: Answer = { kind: 'offline' };

        for (let attempt = 0; attempt <= RETRIES; attempt++) {
            if (attempt > 0) {
                onStatus({ phase: 'waiting', index, attempt });
                await backOnline(navigator.onLine ? 2000 * attempt : 60000);
            }

            answer = await send(action, formFor(fields, file), (percent) =>
                onStatus({ phase: 'sending', index, percent }),
            );

            if (answer.kind !== 'offline') {
                break;
            }
        }

        if (answer.kind === 'done') {
            report.stored += answer.stored;
            report.problems.push(...answer.problems);
        } else if (answer.kind === 'stop') {
            report.problems.push(answer.message);
            report.unsent.push(...files.slice(index));
            report.stopped = true;

            return report;
        } else {
            report.unsent.push(files[index]);
        }
    }

    return report;
}

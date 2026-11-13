/**
 * Shrinks very large photos on the device before upload, to save mobile data
 * (docs/architecture/07-pwa.md, "Camera and uploads").
 *
 * Only photos whose longest edge is over MAX_EDGE are shrunk: the server keeps at most
 * that size anyway, so nothing visible is lost. Drawing the photo again removes all of its
 * hidden data, location included; only the date it was taken is written back, because the
 * gallery uses it. Anything the browser cannot draw (HEIC on most browsers, PDF, TIFF) is
 * sent unchanged and handled on the server as before.
 */

/** Matches config/media.php `sizes.original`. */
export const MAX_EDGE = 4096;

const DRAWABLE = ['image/jpeg', 'image/png', 'image/webp'];
const DATE = /^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/;

export async function shrinkForUpload(file: File): Promise<File> {
    if (
        !DRAWABLE.includes(file.type) ||
        typeof createImageBitmap !== 'function'
    ) {
        return file;
    }

    let bitmap: ImageBitmap;

    try {
        // Applies the phone's rotation tag, so the result is upright.
        bitmap = await createImageBitmap(file, {
            imageOrientation: 'from-image',
        });
    } catch {
        return file;
    }

    try {
        const longest = Math.max(bitmap.width, bitmap.height);

        if (longest <= MAX_EDGE) {
            return file;
        }

        const scale = MAX_EDGE / longest;
        const width = Math.round(bitmap.width * scale);
        const height = Math.round(bitmap.height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (!context) {
            return file;
        }

        // Transparent areas become white, as the server does.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.imageSmoothingQuality = 'high';
        context.drawImage(bitmap, 0, 0, width, height);

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', 0.9),
        );

        if (!blob || blob.size >= file.size) {
            return file;
        }

        const taken =
            file.type === 'image/jpeg'
                ? readTakenDate(new DataView(await file.arrayBuffer()))
                : null;
        const bytes = new Uint8Array(await blob.arrayBuffer());
        const name = file.name.replace(/\.[^.]*$/, '') + '.jpg';

        return new File([taken ? withTakenDate(bytes, taken) : bytes], name, {
            type: 'image/jpeg',
            lastModified: file.lastModified,
        });
    } catch {
        return file;
    } finally {
        bitmap.close();
    }
}

/** EXIF DateTimeOriginal (or DateTime) of a JPEG, as "YYYY:MM:DD HH:MM:SS". */
export function readTakenDate(view: DataView): string | null {
    if (view.byteLength < 4 || view.getUint16(0) !== 0xffd8) {
        return null;
    }

    let offset = 2;

    while (offset + 4 <= view.byteLength) {
        const marker = view.getUint16(offset);
        const length = view.getUint16(offset + 2);

        if ((marker & 0xff00) !== 0xff00 || marker === 0xffda) {
            return null;
        }

        if (
            marker === 0xffe1 &&
            offset + 10 <= view.byteLength &&
            view.getUint32(offset + 4) === 0x45786966 // "Exif"
        ) {
            return readTiffDate(view, offset + 10, offset + 2 + length);
        }

        offset += 2 + length;
    }

    return null;
}

function readTiffDate(
    view: DataView,
    tiff: number,
    end: number,
): string | null {
    if (tiff + 8 > end || end > view.byteLength) {
        return null;
    }

    const little = view.getUint16(tiff) === 0x4949;
    const u16 = (at: number) => view.getUint16(at, little);
    const u32 = (at: number) => view.getUint32(at, little);

    const entries = (ifd: number): Map<number, number> => {
        const found = new Map<number, number>();

        if (ifd + 2 > end) {
            return found;
        }

        const count = Math.min(u16(ifd), 500);

        for (let i = 0; i < count; i++) {
            const entry = ifd + 2 + i * 12;

            if (entry + 12 > end) {
                break;
            }

            found.set(u16(entry), entry);
        }

        return found;
    };

    const ascii = (entry: number): string | null => {
        // Type 2 (ASCII), 20 bytes: stored elsewhere, at the offset in the entry.
        if (u16(entry + 2) !== 2 || u32(entry + 4) !== 20) {
            return null;
        }

        const start = tiff + u32(entry + 8);

        if (start + 19 > end) {
            return null;
        }

        let text = '';

        for (let i = 0; i < 19; i++) {
            text += String.fromCharCode(view.getUint8(start + i));
        }

        return DATE.test(text) ? text : null;
    };

    const ifd0 = entries(tiff + u32(tiff + 4));
    const exifPointer = ifd0.get(0x8769);

    if (exifPointer !== undefined) {
        const original = entries(tiff + u32(exifPointer + 8)).get(0x9003);
        const value = original !== undefined ? ascii(original) : null;

        if (value) {
            return value;
        }
    }

    const modified = ifd0.get(0x0132);

    return modified !== undefined ? ascii(modified) : null;
}

/**
 * Adds a minimal EXIF block holding only DateTimeOriginal to a JPEG made by the browser
 * (which has none). No location, camera or other details.
 */
export function withTakenDate(
    jpeg: Uint8Array<ArrayBuffer>,
    taken: string,
): Uint8Array<ArrayBuffer> {
    if (!DATE.test(taken) || jpeg[0] !== 0xff || jpeg[1] !== 0xd8) {
        return jpeg;
    }

    const tiff = new Uint8Array(64);
    const view = new DataView(tiff.buffer);
    // Big-endian TIFF header, first directory at 8.
    view.setUint16(0, 0x4d4d);
    view.setUint16(2, 42);
    view.setUint32(4, 8);
    // Directory 0: one entry pointing to the EXIF directory at 26.
    view.setUint16(8, 1);
    view.setUint16(10, 0x8769);
    view.setUint16(12, 4);
    view.setUint32(14, 1);
    view.setUint32(18, 26);
    view.setUint32(22, 0);
    // EXIF directory: DateTimeOriginal, 20 ASCII bytes at 44.
    view.setUint16(26, 1);
    view.setUint16(28, 0x9003);
    view.setUint16(30, 2);
    view.setUint32(32, 20);
    view.setUint32(36, 44);
    view.setUint32(40, 0);

    for (let i = 0; i < 19; i++) {
        tiff[44 + i] = taken.charCodeAt(i);
    }

    const header = [0x45, 0x78, 0x69, 0x66, 0, 0]; // "Exif\0\0"
    const length = 2 + header.length + tiff.length;
    const segment = new Uint8Array(2 + length);
    segment.set([0xff, 0xe1, length >> 8, length & 0xff], 0);
    segment.set(header, 4);
    segment.set(tiff, 4 + header.length);

    const result = new Uint8Array(jpeg.length + segment.length);
    result.set(jpeg.subarray(0, 2), 0);
    result.set(segment, 2);
    result.set(jpeg.subarray(2), 2 + segment.length);

    return result;
}

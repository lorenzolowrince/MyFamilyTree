<?php

namespace App\Services\Media;

use App\Enums\MediaStatus;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Models\User;
use App\Notifications\TaggedInPhoto;
use App\Notifications\UploadRejected;
use App\Services\Audit\HistoryRecorder;
use App\Support\TempFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Takes a file out of quarantine (docs/architecture/06-security.md, "File uploads"):
 * scan for malware, re-encode images, remove metadata, make the smaller sizes and previews.
 * The file becomes `ready` only if every step succeeds; otherwise it is deleted and the
 * uploader is told why.
 */
class MediaProcessor
{
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly VirusScanner $scanner,
        private readonly ImageEncoder $images,
        private readonly PdfRenderer $pdf,
        private readonly HistoryRecorder $history,
    ) {}

    /**
     * @throws ScannerUnavailable the file stays in quarantine; the job retries later
     */
    public function process(FamilyPhoto|FamilyDocument $file): void
    {
        if ($file->status !== MediaStatus::Quarantined) {
            return;
        }

        $disk = $this->library->disk();
        $source = TempFile::create('mft-up-');
        $made = [];

        try {
            $stream = $disk->readStream($file->storage_key);

            if (! is_resource($stream)) {
                throw new MediaRejected(__('The uploaded file could not be found. Please upload it again.'));
            }

            file_put_contents($source, $stream);
            fclose($stream);

            $threat = $this->scanner->scan($source);

            if ($threat !== null) {
                $this->history->audit($this->noun($file).'.malware_found', $file->family_id, $this->noun($file), $file->id, null, ['signature' => $threat], $file->uploader);

                throw new MediaRejected(__('The file was refused because the virus scanner flagged it.'));
            }

            // Decided again from the bytes that were actually stored.
            $type = self::detectType($source);
            $folder = $file->storageFolder();
            $sizes = (array) config('media.sizes');

            if ($type === 'application/pdf' && $file instanceof FamilyDocument) {
                $rendered = $this->pdf->render($source, (int) config('media.documents.preview_pages'));
                $made = $rendered['images'];

                $thumb = $this->images->encode($made[0], ['thumb' => (int) $sizes['thumb']]);
                $made[] = $thumb['files']['thumb'];

                $uploads = ["{$folder}/original.pdf" => $source, "{$folder}/thumb.jpg" => $thumb['files']['thumb']];
                $pages = [];

                foreach ($rendered['images'] as $index => $image) {
                    $pages[] = $key = sprintf('%s/page-%03d.jpg', $folder, $index + 1);
                    $uploads[$key] = $image;
                }

                $attributes = [
                    'storage_key' => "{$folder}/original.pdf",
                    'variants' => ['thumb' => "{$folder}/thumb.jpg", 'pages' => $pages],
                    'preview_key' => $pages[0],
                    'page_count' => $rendered['pages'],
                    'mime_type' => 'application/pdf',
                ];
            } elseif (str_starts_with($type, 'image/')) {
                $encoded = $this->images->encode($source, [
                    'original' => (int) $sizes['original'],
                    'display' => (int) $sizes['display'],
                    'thumb' => (int) $sizes['thumb'],
                ]);
                $made = array_values($encoded['files']);

                $uploads = [
                    "{$folder}/original.jpg" => $encoded['files']['original'],
                    "{$folder}/display.jpg" => $encoded['files']['display'],
                    "{$folder}/thumb.jpg" => $encoded['files']['thumb'],
                ];

                $attributes = [
                    'storage_key' => "{$folder}/original.jpg",
                    'variants' => ['display' => "{$folder}/display.jpg", 'thumb' => "{$folder}/thumb.jpg"],
                    'mime_type' => 'image/jpeg',
                    'width' => $encoded['width'],
                    'height' => $encoded['height'],
                ];

                if ($file instanceof FamilyDocument) {
                    $attributes['variants']['pages'] = ["{$folder}/display.jpg"];
                    $attributes['preview_key'] = "{$folder}/display.jpg";
                    $attributes['page_count'] = 1;
                } elseif ($encoded['taken'] !== null && ! $file->taken->isKnown()) {
                    $attributes['taken'] = $encoded['taken'];
                }
            } else {
                throw new MediaRejected(__('This type of file is not accepted.'));
            }

            $total = 0;

            foreach ($uploads as $key => $local) {
                $handle = fopen($local, 'rb');

                if ($handle === false || ! $disk->writeStream($key, $handle)) {
                    throw new ScannerUnavailable("Could not store {$key}.");
                }

                if (is_resource($handle)) {
                    fclose($handle);
                }

                $total += (int) filesize($local);
            }

            $quarantined = $file->storage_key;
            $reserved = $file->size_bytes;

            DB::transaction(function () use ($file, $attributes, $total, $reserved): void {
                $file->forceFill([...$attributes, 'size_bytes' => $total, 'status' => MediaStatus::Ready])->save();
                // The space reserved at upload becomes the space actually taken.
                $this->library->release($file->family_id, $reserved - $total);
            });

            $disk->delete($quarantined);

            $this->announce($file);
        } catch (MediaRejected $rejection) {
            $this->reject($file, $rejection->getMessage());
        } finally {
            @unlink($source);
            array_map(fn (string $path) => @unlink($path), $made);
        }
    }

    /** Refuse a file: delete it, give the space back, tell the uploader. */
    public function reject(FamilyPhoto|FamilyDocument $file, string $reason): void
    {
        if ($file->status !== MediaStatus::Quarantined) {
            return;
        }

        $this->library->disk()->deleteDirectory($file->storageFolder());

        DB::transaction(function () use ($file, $reason): void {
            $this->library->release($file->family_id, $file->size_bytes);
            $file->forceFill(['status' => MediaStatus::Rejected, 'rejection_reason' => $reason, 'variants' => []])->save();
        });

        $this->history->audit($this->noun($file).'.rejected', $file->family_id, $this->noun($file), $file->id, null, ['reason' => $reason], $file->uploader);

        $file->uploader?->notify(new UploadRejected($file->family()->firstOrFail(), (string) ($file->original_filename ?? __('your file')), $reason));
    }

    /** The content type, read from the file itself. */
    public static function detectType(string $path): string
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return match ($type) {
            false => 'application/octet-stream',
            'image/heif', 'image/heic-sequence', 'image/heif-sequence' => 'image/heic',
            default => $type,
        };
    }

    private function announce(FamilyPhoto|FamilyDocument $file): void
    {
        $uploader = $file->uploader;
        $label = $file instanceof FamilyPhoto ? ($file->caption ?? $file->original_filename) : ($file->title ?? $file->original_filename);

        $this->history->activity(
            $file->family_id,
            $this->noun($file).'.uploaded',
            $file instanceof FamilyPhoto
                ? __('added the photo ":name"', ['name' => (string) $label])
                : __('added the document ":name"', ['name' => (string) $label]),
            $this->noun($file),
            $file->id,
            $file->visibility->value,
            $uploader,
        );

        if ($file instanceof FamilyPhoto) {
            self::notifyTagged($file, array_values(array_map(strval(...), $file->people()->pluck('family_members.id')->all())), $uploader);
        }
    }

    /**
     * Tell people with an account that they were tagged in a photo they are allowed to see.
     *
     * @param  list<string>  $memberIds
     */
    public static function notifyTagged(FamilyPhoto $photo, array $memberIds, ?User $actor): void
    {
        if ($memberIds === [] || ! $photo->isReady() || $photo->visibility->value !== 'family') {
            return;
        }

        try {
            $users = User::query()
                ->whereIn('id', DB::table('family_user')
                    ->where('family_id', $photo->family_id)
                    ->where('status', 'active')
                    ->whereIn('linked_member_id', $memberIds)
                    ->select('user_id'))
                ->when($actor !== null, fn ($query) => $query->whereKeyNot($actor?->id))
                ->get();

            Notification::send($users, new TaggedInPhoto($photo->family()->firstOrFail(), $photo->id, $actor?->name));
        } catch (Throwable $exception) {
            // A notification that cannot be sent must not undo a finished upload.
            report($exception);
        }
    }

    private function noun(FamilyPhoto|FamilyDocument $file): string
    {
        return $file instanceof FamilyPhoto ? 'photo' : 'document';
    }
}

<?php

namespace App\Actions\Media;

use App\Jobs\ProcessMediaUpload;
use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Media\MediaLibrary;
use App\Services\Media\MediaProcessor;
use Closure;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Accepts one uploaded file into quarantine and queues its safety checks.
 *
 * The type is decided from the file's content. The name the file arrived with is kept only
 * as a label: the storage key is generated here.
 */
class UploadMedia
{
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly HistoryRecorder $history,
    ) {}

    /**
     * @param  'photo'|'document'  $kind
     * @param  Closure  $fill  receives the new record; sets caption, album, visibility... before the first save
     * @param  Closure|null  $saved  receives the record after the first save and before the checks start (tags)
     *
     * @throws DomainException with a message for the uploader when the file is not accepted
     */
    public function handle(Family $family, User $user, UploadedFile $upload, string $kind, Closure $fill, ?Closure $saved = null): FamilyPhoto|FamilyDocument
    {
        $path = $upload->getRealPath();
        $name = self::label($upload->getClientOriginalName());

        if (! $upload->isValid() || $path === false || ! is_file($path)) {
            throw new DomainException(__(':file: the upload did not complete. Please try again.', ['file' => $name]));
        }

        $limits = (array) config($kind === 'photo' ? 'media.photos' : 'media.documents');
        $size = (int) filesize($path);
        $type = MediaProcessor::detectType($path);

        if (! in_array($type, (array) $limits['types'], true)) {
            throw new DomainException($kind === 'photo'
                ? __(':file: only JPEG, PNG, WebP and HEIC photos are accepted.', ['file' => $name])
                : __(':file: only PDF files and scanned images (JPEG, PNG, WebP, HEIC, TIFF) are accepted.', ['file' => $name]));
        }

        if ($size < 1 || $size > (int) $limits['max_bytes']) {
            throw new DomainException(__(':file: the file is larger than :max.', ['file' => $name, 'max' => Number::fileSize((int) $limits['max_bytes'])]));
        }

        $hash = (string) hash_file('sha256', $path);
        $model = $kind === 'photo' ? FamilyPhoto::class : FamilyDocument::class;

        if ($model::query()->inFamily($family)->where('sha256', $hash)->where('status', '<>', 'rejected')->exists()) {
            throw new DomainException(__(':file: this file is already in the family\'s library.', ['file' => $name]));
        }

        if (! $this->library->reserve($family, $size)) {
            throw new DomainException(__(':file: the family\'s storage is full. Delete files you no longer need, or ask the owner about more space.', ['file' => $name]));
        }

        /** @var FamilyPhoto|FamilyDocument $file */
        $file = new $model;
        $file->id = (string) Str::uuid7();
        $file->family_id = $family->id;
        $file->storage_key = $file->storageFolder().'/quarantine';
        $file->original_filename = $name;
        $file->mime_type = $type;
        $file->size_bytes = $size;
        $file->sha256 = $hash;
        $file->uploaded_by = $user->id;

        try {
            $stream = fopen($path, 'rb');

            if ($stream === false || ! $this->library->disk()->writeStream($file->storage_key, $stream)) {
                throw new DomainException(__(':file: the file could not be stored. Please try again.', ['file' => $name]));
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            $fill($file);
            $file->save();
        } catch (\Throwable $exception) {
            $this->library->disk()->delete($file->storage_key);
            $this->library->release($family->id, $size);

            throw $exception;
        }

        $this->history->audit("{$kind}.uploaded", $family->id, $kind, $file->id, null, [
            'filename' => $name, 'type' => $type, 'size_bytes' => $size, 'sha256' => $hash, 'visibility' => $file->visibility->value,
        ], $user);

        if ($saved !== null) {
            $saved($file);
        }

        ProcessMediaUpload::dispatch($kind, $file->id);

        return $file;
    }

    /** The upload's own name, reduced to something safe to show. Never used as a path. */
    private static function label(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name));

        return $name === '' ? 'file' : Str::limit($name, 150, '');
    }
}

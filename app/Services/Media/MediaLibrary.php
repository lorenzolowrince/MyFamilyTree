<?php

namespace App\Services\Media;

use App\Models\Family;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The private store behind photos and documents, and each family's storage allowance.
 *
 * `families.storage_bytes_used` always equals the sum of `size_bytes` of the family's files
 * that still hold storage: everything except rejected files (already deleted) and files
 * erased for good.
 */
class MediaLibrary
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('media.disk'));
    }

    /**
     * Take space from the family's allowance. One statement, so two uploads racing for the
     * last megabyte cannot both win.
     */
    public function reserve(Family $family, int $bytes): bool
    {
        return DB::update(
            'UPDATE families SET storage_bytes_used = storage_bytes_used + ? WHERE id = ? AND storage_bytes_used + ? <= storage_quota_bytes',
            [$bytes, $family->id, $bytes],
        ) === 1;
    }

    /** Give space back (or take more, with a negative number, once a file's final size is known). */
    public function release(string $familyId, int $bytes): void
    {
        DB::update('UPDATE families SET storage_bytes_used = GREATEST(storage_bytes_used - ?, 0) WHERE id = ?', [$bytes, $familyId]);
    }

    /** Remove every stored form of a file. */
    public function erase(FamilyPhoto|FamilyDocument $file): void
    {
        $this->disk()->delete($file->storageKeys());
    }

    /**
     * @return array{used: int, quota: int, photos: int, documents: int, deleted: int}
     */
    public function usage(Family $family): array
    {
        $family->refresh();

        $sum = fn (string $table, bool $trashed): int => (int) DB::table($table)
            ->where('family_id', $family->id)
            ->where('status', '<>', 'rejected')
            ->when($trashed, fn ($query) => $query->whereNotNull('deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->sum('size_bytes');

        return [
            'used' => $family->storage_bytes_used,
            'quota' => $family->storage_quota_bytes,
            'photos' => $sum('family_photos', false),
            'documents' => $sum('family_documents', false),
            'deleted' => $sum('family_photos', true) + $sum('family_documents', true),
        ];
    }
}

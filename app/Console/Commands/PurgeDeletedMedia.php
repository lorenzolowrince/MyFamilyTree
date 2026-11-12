<?php

namespace App\Console\Commands;

use App\Enums\MediaStatus;
use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Services\Media\MediaLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Erases photos and documents that were deleted more than 30 days ago (`media.purge_after_days`)
 * and gives their space back to the family. Until then the files are kept, so a deletion made
 * by mistake can still be reversed in the database. Also clears out refused uploads nobody
 * dismissed.
 */
#[Signature('media:purge {--days= : Override the number of days deleted files are kept}')]
#[Description('Erase photos and documents deleted more than 30 days ago and release their storage')]
class PurgeDeletedMedia extends Command
{
    public function handle(MediaLibrary $library): int
    {
        $days = $this->option('days') === null ? (int) config('media.purge_after_days') : (int) $this->option('days');
        $before = now()->subDays(max(0, $days));
        $erased = 0;

        foreach ([FamilyPhoto::class, FamilyDocument::class] as $model) {
            $model::query()->onlyTrashed()->where('deleted_at', '<=', $before)->chunkById(100, function ($files) use ($library, &$erased): void {
                foreach ($files as $file) {
                    $library->erase($file);

                    if ($file->status !== MediaStatus::Rejected) {
                        $library->release($file->family_id, $file->size_bytes);
                    }

                    $file->forceDelete();
                    $erased++;
                }
            });

            // Refused uploads hold no file; the note about them is kept for a month.
            $model::query()->where('status', MediaStatus::Rejected->value)->where('updated_at', '<=', $before)->forceDelete();
        }

        $this->info("Erased {$erased} deleted file(s).");

        return self::SUCCESS;
    }
}

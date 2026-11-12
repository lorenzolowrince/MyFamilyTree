<?php

namespace App\Console\Commands;

use App\Models\GedcomImport;
use App\Models\ReportExport;
use App\Services\Media\MediaLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deletes report files once they have expired (7 days by default), and GEDCOM uploads that
 * were never confirmed. Both hold a copy of a family's personal information that nobody
 * needs any more.
 */
#[Signature('reports:purge')]
#[Description('Delete expired report files and abandoned GEDCOM uploads')]
class PurgeExpiredReports extends Command
{
    public function handle(MediaLibrary $library): int
    {
        $reports = 0;

        ReportExport::query()->where('expires_at', '<=', now())->chunkById(100, function ($expired) use ($library, &$reports): void {
            foreach ($expired as $report) {
                if ($report->storage_key !== null) {
                    $library->disk()->delete($report->storage_key);
                }

                $report->delete();
                $reports++;
            }
        });

        $uploads = 0;

        GedcomImport::query()
            ->whereIn('status', ['uploaded', 'parsing', 'preview_ready', 'failed'])
            ->where('updated_at', '<=', now()->subDays((int) config('reports.keep_days')))
            ->chunkById(100, function ($abandoned) use ($library, &$uploads): void {
                foreach ($abandoned as $import) {
                    $library->disk()->delete($import->storage_key);
                    $import->forceFill([
                        'status' => $import->status === 'failed' ? 'failed' : 'cancelled',
                        'summary' => array_diff_key($import->summary, ['people' => true]),
                    ])->save();
                    $uploads++;
                }
            });

        $this->info("Deleted {$reports} expired report(s) and {$uploads} abandoned upload(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\GedcomImport;
use App\Models\User;
use App\Services\Gedcom\GedcomImporter;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One step of a GEDCOM import, run in the background: `prepare` (read the file and build the
 * preview) or `run` (apply it after the preview was confirmed).
 */
class ProcessGedcomImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 880;

    /** @param 'prepare'|'run' $step */
    public function __construct(
        public readonly string $importId,
        public readonly string $step,
        public readonly ?string $userId = null,
    ) {
        $this->onQueue('imports');
    }

    public function handle(GedcomImporter $importer): void
    {
        $import = GedcomImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        try {
            if ($this->step === 'prepare' && $import->status === 'uploaded') {
                $importer->prepare($import);
            } elseif ($this->step === 'run' && $import->status === 'importing') {
                $importer->run($import, User::query()->findOrFail($this->userId));
            }
        } catch (DomainException $exception) {
            // A reason meant for the person who uploaded the file.
            $import->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        GedcomImport::query()
            ->whereKey($this->importId)
            ->whereIn('status', ['uploaded', 'parsing', 'importing'])
            ->update(['status' => 'failed', 'error' => __('Something went wrong while processing the file. Nothing was changed in the tree.')]);
    }
}

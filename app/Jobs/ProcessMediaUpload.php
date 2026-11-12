<?php

namespace App\Jobs;

use App\Models\FamilyDocument;
use App\Models\FamilyPhoto;
use App\Services\Media\MediaProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks one uploaded file and releases it from quarantine (MediaProcessor).
 *
 * If the virus scanner or the storage is down, the job is retried for about an hour; a file
 * is never released unscanned. If it still cannot be checked, it is refused.
 */
class ProcessMediaUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 300;

    /** @param 'photo'|'document' $kind */
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
    ) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 60, 120, 300, 600, 1200];
    }

    public function handle(MediaProcessor $processor): void
    {
        $file = $this->file();

        if ($file !== null) {
            $processor->process($file);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $file = $this->file();

        if ($file !== null) {
            app(MediaProcessor::class)->reject($file, __('The file could not be checked for safety. Please try uploading it again later.'));
        }
    }

    private function file(): FamilyPhoto|FamilyDocument|null
    {
        return $this->kind === 'photo'
            ? FamilyPhoto::query()->find($this->id)
            : FamilyDocument::query()->find($this->id);
    }
}

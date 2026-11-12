<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\Media\MediaLibrary;
use App\Services\Reports\HtmlToPdf;
use App\Services\Reports\ReportBuilder;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makes the PDF of one requested report and stores it for download.
 * Built with the privacy rules of the person who asked, at the moment it is built.
 */
class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 20;

    public function __construct(public readonly string $reportId)
    {
        $this->onQueue('reports');
    }

    public function handle(ReportBuilder $builder, HtmlToPdf $pdf, MediaLibrary $library): void
    {
        $report = ReportExport::query()->find($this->reportId);
        $requester = $report === null ? null : User::query()->find($report->requested_by);

        if ($report === null || $requester === null || ! in_array($report->status, ['pending', 'processing'], true)) {
            return;
        }

        $report->forceFill(['status' => 'processing'])->save();

        try {
            $built = $builder->build($report, $requester);
        } catch (DomainException $exception) {
            // A reason meant for the requester; trying again would not help.
            $report->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();

            return;
        }

        $bytes = $pdf->render($built['html'], $built['footer'], $built['landscape'], $built['paper']);
        $key = "families/{$report->family_id}/reports/{$report->id}.pdf";

        $library->disk()->put($key, $bytes);

        $report->forceFill([
            'status' => 'ready',
            'storage_key' => $key,
            'size_bytes' => strlen($bytes),
            'error' => null,
            // What the requester could see when this was printed (ReportController::usable).
            'parameters' => [...$report->parameters, 'made_as' => $built['role']],
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        ReportExport::query()
            ->whereKey($this->reportId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => __('The report could not be made. Please try again in a few minutes.')]);
    }
}

<?php

namespace App\Services\Reports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Prints HTML to PDF with Chromium (the Gotenberg service).
 *
 * The HTML must be complete in itself: styles inline, images as data. Chromium runs with
 * JavaScript off and may load nothing from the network, so a report can only ever contain
 * what the application put into it.
 */
class HtmlToPdf
{
    /** Paper sizes in inches (width, height), portrait. */
    private const PAPER = ['A4' => [8.27, 11.7], 'A3' => [11.7, 16.54]];

    /**
     * @param  'A4'|'A3'  $paper
     *
     * @throws RuntimeException when the PDF could not be made
     */
    public function render(string $html, string $footer, bool $landscape = false, string $paper = 'A4'): string
    {
        [$width, $height] = self::PAPER[$paper];

        try {
            $response = Http::timeout((int) config('reports.timeout'))
                ->attach('files', $html, 'index.html')
                ->attach('files', $footer, 'footer.html')
                ->post(rtrim((string) config('reports.gotenberg_url'), '/').'/forms/chromium/convert/html', [
                    'paperWidth' => (string) $width,
                    'paperHeight' => (string) $height,
                    'landscape' => $landscape ? 'true' : 'false',
                    'marginTop' => '0.55',
                    'marginBottom' => '0.7',
                    'marginLeft' => '0.55',
                    'marginRight' => '0.55',
                    'printBackground' => 'true',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The PDF service could not be reached.', previous: $exception);
        }

        $pdf = $response->body();

        if (! $response->successful() || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('The PDF service refused the report: HTTP '.$response->status());
        }

        return $pdf;
    }
}

<?php

namespace App\Services\Media;

use App\Support\TempFile;
use Illuminate\Support\Facades\Process;

/**
 * Reads a PDF with Ghostscript in its locked-down mode (`-dSAFER`: no file or network access
 * beyond the one input) to count its pages and draw the first pages as images. People look at
 * those images in the browser; the PDF itself is only ever sent as a download.
 */
class PdfRenderer
{
    /**
     * @return array{pages: int, images: list<string>} temporary JPEG files the caller must delete
     *
     * @throws MediaRejected when the PDF cannot be opened (damaged, or password-protected)
     */
    public function render(string $path, int $maxPages): array
    {
        $count = Process::timeout(60)->run([
            'gs', '-q', '-dNODISPLAY', '-dSAFER', '-dBATCH', '-dNOPAUSE',
            '--permit-file-read='.$path,
            '-c', '('.self::escape($path).') (r) file runpdfbegin pdfpagecount = quit',
        ]);

        $pages = (int) trim($count->output());

        if (! $count->successful() || $pages < 1) {
            throw new MediaRejected(__('The PDF could not be opened. It may be damaged or protected by a password.'));
        }

        $prefix = TempFile::path('mft-pdf-');
        $last = min($pages, max(1, $maxPages));

        $render = Process::timeout(180)->run([
            'gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE',
            '-sDEVICE=jpeg', '-dJPEGQ=85', '-r110',
            '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            '-dFirstPage=1', '-dLastPage='.$last,
            '-sOutputFile='.$prefix.'-%03d.jpg',
            $path,
        ]);

        $images = [];

        for ($page = 1; $page <= $last; $page++) {
            $image = sprintf('%s-%03d.jpg', $prefix, $page);

            if (is_file($image) && filesize($image) > 0) {
                $images[] = $image;
            }
        }

        if (! $render->successful() || $images === []) {
            array_map(fn (string $image) => @unlink($image), $images);

            throw new MediaRejected(__('The PDF could not be opened. It may be damaged or protected by a password.'));
        }

        return ['pages' => $pages, 'images' => $images];
    }

    /** A path as a PostScript string literal. */
    private static function escape(string $path): string
    {
        return strtr($path, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}

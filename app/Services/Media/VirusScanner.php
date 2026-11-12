<?php

namespace App\Services\Media;

interface VirusScanner
{
    /**
     * Scan one local file.
     *
     * @return string|null the name of what was found, or null when the file is clean
     *
     * @throws ScannerUnavailable when the file could not be scanned at all
     */
    public function scan(string $path): ?string;
}

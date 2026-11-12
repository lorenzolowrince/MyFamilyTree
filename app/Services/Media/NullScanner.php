<?php

namespace App\Services\Media;

/** Used only when scanning is switched off in configuration (`CLAMAV_ENABLED=false`). */
class NullScanner implements VirusScanner
{
    public function scan(string $path): ?string
    {
        return null;
    }
}

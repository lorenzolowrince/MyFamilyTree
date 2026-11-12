<?php

namespace App\Support;

use RuntimeException;

/** Private working files with names nobody can guess. The caller deletes them when done. */
final class TempFile
{
    /** Create an empty file and return its path. */
    public static function create(string $prefix = 'mft-'): string
    {
        $path = self::path($prefix);

        // 'x': fail rather than follow something already sitting at that path.
        $handle = fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        fclose($handle);
        chmod($path, 0600);

        return $path;
    }

    /** A path that does not exist yet, for tools that write several numbered files from one prefix. */
    public static function path(string $prefix = 'mft-'): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(16));
    }
}

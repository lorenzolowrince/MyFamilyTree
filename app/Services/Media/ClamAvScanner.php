<?php

namespace App\Services\Media;

/**
 * Sends a file to the ClamAV daemon over TCP (the INSTREAM command), so the daemon needs no
 * access to the application's disk.
 */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout = 60,
    ) {}

    public function scan(string $path): ?string
    {
        $file = @fopen($path, 'rb');

        if ($file === false) {
            throw new ScannerUnavailable('The file to scan could not be opened.');
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $code, $error, 10);

        if ($socket === false) {
            fclose($file);

            throw new ScannerUnavailable("ClamAV is not reachable at {$this->host}:{$this->port}: {$error}");
        }

        try {
            stream_set_timeout($socket, $this->timeout);
            $this->write($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);

                if ($chunk === false) {
                    throw new ScannerUnavailable('The file to scan could not be read.');
                }

                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }
            }

            $this->write($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        if (str_ends_with($reply, 'OK')) {
            return null;
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match) === 1) {
            return $match[1];
        }

        throw new ScannerUnavailable('ClamAV could not scan the file: '.($reply === '' ? 'no reply' : $reply));
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $length = strlen($data);

        for ($sent = 0; $sent < $length;) {
            $written = @fwrite($socket, substr($data, $sent));

            if ($written === false || $written === 0) {
                throw new ScannerUnavailable('The connection to ClamAV was lost.');
            }

            $sent += $written;
        }
    }
}

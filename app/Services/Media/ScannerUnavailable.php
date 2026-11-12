<?php

namespace App\Services\Media;

use RuntimeException;

/** The virus scanner could not give an answer. The file stays in quarantine and is retried. */
class ScannerUnavailable extends RuntimeException {}

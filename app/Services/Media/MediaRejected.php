<?php

namespace App\Services\Media;

use RuntimeException;

/** The file failed a safety check. The message is shown to the person who uploaded it. */
class MediaRejected extends RuntimeException {}

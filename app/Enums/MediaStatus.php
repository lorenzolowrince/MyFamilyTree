<?php

namespace App\Enums;

/** Where an uploaded file is in the safety pipeline. Only `Ready` files are ever served. */
enum MediaStatus: string
{
    /** Stored, not yet scanned or re-encoded. */
    case Quarantined = 'quarantined';

    case Ready = 'ready';

    /** Failed the scan or could not be read. The file itself has been deleted. */
    case Rejected = 'rejected';
}

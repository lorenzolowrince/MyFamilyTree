<?php

namespace App\Exceptions;

use DomainException;

/** Someone else saved the record after this person opened it (optimistic locking). */
class VersionConflictException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('Someone else changed this record while you were editing. Reload the page to see the latest version, then make your changes again.'));
    }
}

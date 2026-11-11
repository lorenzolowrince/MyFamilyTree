<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';

    /** Paused by the user. Signing in again offers to reactivate. */
    case Deactivated = 'deactivated';

    /** Blocked by a Super Administrator. Cannot sign in. */
    case Suspended = 'suspended';
}

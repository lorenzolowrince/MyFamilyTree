<?php

namespace App\Enums;

/** Values of `login_activities.event` (mirrored by a CHECK constraint). */
enum LoginEvent: string
{
    case Login = 'login';
    case Logout = 'logout';
    case Failed = 'failed';
    case MfaFailed = 'mfa_failed';
    case Lockout = 'lockout';
    case PasswordReset = 'password_reset';
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::Logout => 'Signed out',
            self::Failed => 'Failed sign-in attempt',
            self::MfaFailed => 'Wrong two-factor code',
            self::Lockout => 'Too many attempts (temporarily locked)',
            self::PasswordReset => 'Password reset',
            self::MfaEnabled => 'Two-factor authentication turned on',
            self::MfaDisabled => 'Two-factor authentication turned off',
        };
    }
}

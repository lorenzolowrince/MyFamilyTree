<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Used when a password-reset link could not be sent (unknown email, or one was sent a moment
 * ago). It answers exactly like the success case, so the form never reveals whether an
 * email address has an account (docs/architecture/06-security.md, "Password reset").
 */
class SamePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status) {}

    /**
     * {@inheritDoc}
     */
    public function toResponse($request)
    {
        $message = trans(Password::RESET_LINK_SENT);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message)->withInput($request->only(Fortify::email()));
    }
}

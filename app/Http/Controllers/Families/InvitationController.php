<?php

namespace App\Http\Controllers\Families;

use App\Actions\Collaboration\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Models\FamilyInvitation;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page behind an invitation link. Anyone holding the link can see which family invited
 * them; joining requires signing in with the invited, verified email address.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);
        $user = $request->user();

        // Remembered so sign-in or registration brings the person back here.
        if ($user === null) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return Inertia::render('invitations/show', [
            'token' => $token,
            'invitation' => $invitation === null ? null : [
                'family' => $invitation->family?->name,
                'inviter' => $invitation->inviter?->name,
                'role' => $invitation->role->label(),
                'message' => $invitation->message,
                'state' => $invitation->state(),
                // Enough to recognise the address without disclosing it to whoever holds the link.
                'email_hint' => self::mask($invitation->email),
            ],
            'viewer' => $user instanceof User ? [
                'email' => $user->email,
                'verified' => $user->hasVerifiedEmail(),
                'matches' => $invitation !== null && strcasecmp($user->email, $invitation->email) === 0,
            ] : null,
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        $invitation = $this->find($token);

        abort_if($invitation === null, 404);

        /** @var User $user */
        $user = $request->user();

        try {
            $accept->handle($invitation, $user);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        $family = $invitation->family()->firstOrFail();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :family.', ['family' => $family->name])]);

        return to_route('families.tree', $family);
    }

    private function find(string $token): ?FamilyInvitation
    {
        if (strlen($token) !== 64 || ! ctype_alnum($token)) {
            return null;
        }

        return FamilyInvitation::query()
            ->where('token_hash', FamilyInvitation::hashToken($token))
            ->with(['family', 'inviter'])
            ->first();
    }

    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }
}

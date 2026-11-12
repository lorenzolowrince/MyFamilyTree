<?php

namespace App\Actions\Collaboration;

use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Notifications\FamilyInvitationSent;
use App\Services\Audit\HistoryRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invites someone to a family by email. An open invitation to the same address is replaced:
 * the old link stops working and a new one is sent.
 */
class InviteToFamily
{
    public function __construct(private readonly HistoryRecorder $history) {}

    /**
     * @throws DomainException when the person is already a member
     */
    public function handle(Family $family, User $inviter, string $email, FamilyRole $role, ?string $message = null, ?string $memberId = null): FamilyInvitation
    {
        $email = Str::lower(trim($email));

        $alreadyMember = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->whereIn('user_id', User::query()->where('email', $email)->select('id'))
            ->exists();

        if ($alreadyMember) {
            throw new DomainException(__('That person is already part of this family.'));
        }

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($family, $inviter, $email, $role, $message, $memberId, $token): FamilyInvitation {
            FamilyInvitation::query()
                ->where('family_id', $family->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = new FamilyInvitation;
            $invitation->family_id = $family->id;
            $invitation->email = $email;
            $invitation->role = $role;
            $invitation->token_hash = FamilyInvitation::hashToken($token);
            $invitation->member_id = $memberId;
            $invitation->invited_by = $inviter->id;
            $invitation->message = $message !== null && trim($message) !== '' ? trim($message) : null;
            $invitation->expires_at = now()->addDays(FamilyInvitation::VALID_DAYS);
            $invitation->save();

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new FamilyInvitationSent(
            $family->name,
            $inviter->name,
            $role->label(),
            route('invitations.show', ['token' => $token]),
            $invitation->expires_at->setTimezone($inviter->timezone)->format('j M Y'),
            $invitation->message,
        ));

        $this->history->audit('invitation.sent', $family->id, 'invitation', $invitation->id, null, ['email' => $email, 'role' => $role->value], $inviter);
        $this->history->activity($family->id, 'invitation.sent', __('invited someone to the family as :role', ['role' => $role->label()]), 'invitation', $invitation->id, 'family', $inviter);

        return $invitation;
    }
}

<?php

namespace App\Actions\Collaboration;

use App\Enums\FamilyRole;
use App\Models\FamilyInvitation;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Notifications\MemberJoinedFamily;
use App\Services\Audit\HistoryRecorder;
use App\Services\Authorization\FamilyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Turns an invitation into a membership. The signed-in account must have the email address
 * the invitation was sent to, verified: a forwarded link does not let someone else in.
 */
class AcceptInvitation
{
    public function __construct(
        private readonly HistoryRecorder $history,
        private readonly FamilyAccess $access,
    ) {}

    /**
     * @throws DomainException with a message safe to show
     */
    public function handle(FamilyInvitation $invitation, User $user): FamilyMembership
    {
        if (! $user->hasVerifiedEmail() || strcasecmp($user->email, $invitation->email) !== 0) {
            throw new DomainException(__('This invitation was sent to a different email address. Sign in with the address it was sent to.'));
        }

        $family = $invitation->family()->firstOrFail();

        $membership = DB::transaction(function () use ($invitation, $user, $family): FamilyMembership {
            $locked = FamilyInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new DomainException(__('This invitation is no longer valid. Ask for a new one.'));
            }

            $membership = FamilyMembership::query()->where('family_id', $family->id)->where('user_id', $user->id)->first();

            if ($membership === null) {
                $membership = new FamilyMembership;
                $membership->family_id = $family->id;
                $membership->user_id = $user->id;
                $membership->role = $locked->role;
                $membership->invited_by = $locked->invited_by;

                // "This invitation is for this person in the tree", unless someone already is them.
                $taken = $locked->member_id !== null && FamilyMembership::query()
                    ->where('family_id', $family->id)
                    ->where('linked_member_id', $locked->member_id)
                    ->exists();

                $membership->linked_member_id = $taken ? null : $locked->member_id;
                $membership->forceFill(['joined_at' => now()])->save();
            }

            $locked->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            return $membership;
        });

        $this->access->forgetMemberships();

        $this->history->audit('invitation.accepted', $family->id, 'invitation', $invitation->id, null, ['role' => $membership->role->value], $user);
        $this->history->activity($family->id, 'member.joined', __('joined the family as :role', ['role' => $membership->role->label()]), 'user', $user->id, 'family', $user);

        $admins = User::query()
            ->whereIn('id', FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('status', 'active')
                ->whereIn('role_id', [FamilyRole::Owner->id(), FamilyRole::Admin->id()])
                ->select('user_id'))
            ->whereKeyNot($user->id)
            ->get();

        Notification::send($admins, new MemberJoinedFamily($family, $user->name, $membership->role->label()));

        return $membership;
    }
}

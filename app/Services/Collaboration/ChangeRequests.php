<?php

namespace App\Services\Collaboration;

use App\Actions\Members\CreateMember;
use App\Actions\Members\DeleteMember;
use App\Actions\Members\UpdateMember;
use App\Actions\Relationships\AddRelative;
use App\Actions\Timeline\SaveTimelineEntry;
use App\Enums\FamilyPermission;
use App\Enums\FamilyRole;
use App\Enums\RelativeKind;
use App\Exceptions\InvalidRelationshipException;
use App\Exceptions\VersionConflictException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\FamilyMembership;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Notifications\ChangeRequestDecided;
use App\Notifications\ChangeRequestSubmitted;
use App\Services\Audit\HistoryRecorder;
use App\Services\Privacy\MemberPrivacy;
use App\Support\FuzzyDate\FuzzyDate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Proposals: a change that waits for a reviewer before it touches the tree.
 *
 * Submitting stores the already-validated input. Approving runs the same Action a direct edit
 * would, so the rules (cycle checks, privacy, optimistic locking) cannot differ between the
 * two paths.
 */
class ChangeRequests
{
    private const LEVELS = ['family' => 0, 'restricted' => 1, 'private' => 2];

    /** Member fields shown in a diff, with their labels. */
    public const MEMBER_FIELDS = [
        'full_name' => 'Full name', 'nickname' => 'Known as', 'given_names' => 'Given names', 'surname' => 'Surname',
        'maiden_name' => 'Maiden or birth name', 'gender' => 'Gender', 'living_status' => 'Living?',
        'birth' => 'Born', 'birth_place' => 'Place of birth', 'death' => 'Died', 'death_place' => 'Place of death',
        'hometown' => 'Hometown', 'nationality' => 'Nationality', 'occupation' => 'Occupation', 'education' => 'Education',
        'biography' => 'Life story', 'notes' => 'Research notes', 'visibility' => 'Who can see this person',
    ];

    public function __construct(
        private readonly MemberPrivacy $privacy,
        private readonly ChangeMode $mode,
        private readonly HistoryRecorder $history,
    ) {}

    // --- Submitting --------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    public function proposeMemberUpdate(Family $family, User $user, FamilyMember $member, array $data, int $version): ?ChangeRequest
    {
        $current = self::snapshot($member);
        $changes = [];

        foreach ($data as $field => $value) {
            if (array_key_exists($field, self::MEMBER_FIELDS) && self::display($field, $value) !== self::display($field, $current[$field] ?? null)) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return null;
        }

        return $this->store($family, $user, 'member', 'update', $member->id, $changes, $version,
            __('Edit :name', ['name' => $member->full_name]), $member->visibility->value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function proposeMemberCreate(Family $family, User $user, array $data): ChangeRequest
    {
        return $this->store($family, $user, 'member', 'create', null, $data, null,
            __('Add :name', ['name' => (string) ($data['full_name'] ?? '')]));
    }

    public function proposeMemberDelete(Family $family, User $user, FamilyMember $member): ChangeRequest
    {
        return $this->store($family, $user, 'member', 'delete', $member->id, [], $member->version,
            __('Remove :name from the tree', ['name' => $member->full_name]), $member->visibility->value);
    }

    /**
     * @param  FamilyMember|array<string, mixed>  $relative
     * @param  array<string, mixed>  $options
     */
    public function proposeRelative(Family $family, User $user, FamilyMember $anchor, RelativeKind $kind, FamilyMember|array $relative, array $options): ChangeRequest
    {
        $name = $relative instanceof FamilyMember ? $relative->full_name : (string) ($relative['full_name'] ?? '');

        $visibility = $relative instanceof FamilyMember
            ? self::stricter($anchor->visibility->value, $relative->visibility->value)
            : $anchor->visibility->value;

        return $this->store($family, $user, 'relationship', 'create', $anchor->id, [
            'kind' => $kind->value,
            'existing_member_id' => $relative instanceof FamilyMember ? $relative->id : null,
            'member' => $relative instanceof FamilyMember ? null : $relative,
            'options' => $options,
            'relative_name' => $name,
        ], null, __('Add :name as :kind of :anchor', ['name' => $name, 'kind' => $kind->value, 'anchor' => $anchor->full_name]), $visibility);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function proposeTimelineEntry(Family $family, User $user, array $data): ChangeRequest
    {
        return $this->store($family, $user, 'timeline', 'create', null, $data, null,
            __('Add ":title" to the timeline', ['title' => (string) ($data['title'] ?? '')]));
    }

    // --- Deciding ----------------------------------------------------------------------

    /** May this person approve or reject this request? Never their own, and only what they could do directly. */
    public function canReview(User $user, ChangeRequest $request, Family $family): bool
    {
        if (! $request->isPending() || $request->submitted_by === $user->id) {
            return false;
        }

        $viewer = $this->privacy->viewer($user, $family);

        if (! $viewer->has(FamilyPermission::ChangesReview) || ! in_array($request->visibility, $viewer->visibleLevels(), true)) {
            return false;
        }

        return ($request->kind() === 'member.delete'
            ? $this->mode->forDelete($user, $family)
            : $this->mode->forEdit($user, $family)) === ChangeMode::DIRECT;
    }

    /**
     * Apply the proposal to the tree.
     *
     * @throws VersionConflictException when the record changed since the proposal and `$force` is false
     * @throws InvalidRelationshipException when the tree no longer allows the change
     * @throws DomainException when the target no longer exists
     */
    public function approve(ChangeRequest $request, User $reviewer, ?string $note = null, bool $force = false): void
    {
        $family = $request->family()->firstOrFail();
        $submitter = $request->submitter()->firstOrFail();

        DB::transaction(function () use ($request, $reviewer, $note, $force, $family, $submitter): void {
            $locked = ChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new DomainException(__('This proposal has already been decided.'));
            }

            $this->apply($locked, $family, $submitter, $reviewer, $force);

            $locked->forceFill([
                'status' => 'approved',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'applied_at' => now(),
                'review_note' => $note,
            ])->save();
        });

        $this->history->audit('change_request.approved', $family->id, 'change_request', $request->id, null, ['summary' => $request->summary, 'note' => $note], $reviewer);
        $this->history->activity($family->id, 'change_request.approved', __('approved the proposal ":summary"', ['summary' => $request->summary]), 'change_request', $request->id, $request->visibility, $reviewer);

        $submitter->notify(new ChangeRequestDecided($request->refresh(), $family));
    }

    public function reject(ChangeRequest $request, User $reviewer, ?string $note = null): void
    {
        $family = $request->family()->firstOrFail();

        $updated = ChangeRequest::query()->whereKey($request->id)->where('status', 'pending')->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        if ($updated === 0) {
            throw new DomainException(__('This proposal has already been decided.'));
        }

        $this->history->audit('change_request.rejected', $family->id, 'change_request', $request->id, null, ['summary' => $request->summary, 'note' => $note], $reviewer);

        $request->submitter?->notify(new ChangeRequestDecided($request->refresh(), $family));
    }

    public function withdraw(ChangeRequest $request): void
    {
        ChangeRequest::query()->whereKey($request->id)->where('status', 'pending')->update(['status' => 'withdrawn']);
    }

    // --- Presenting --------------------------------------------------------------------

    /**
     * What a reviewer sees: the proposal, and for edits the current and proposed value of
     * each field that would change.
     *
     * @return array<string, mixed>
     */
    public function present(ChangeRequest $request, User $viewer, Family $family): array
    {
        $rows = [];
        $conflict = false;
        $missing = false;
        $payload = $request->payload;

        if ($request->target_type === 'member' && in_array($request->action, ['update', 'delete'], true)) {
            $member = FamilyMember::query()->inFamily($family)->find($request->target_id);
            $missing = $member === null;

            if ($member !== null && $request->isPending()) {
                $conflict = $request->base_version !== null && $member->version !== $request->base_version;
                $current = self::snapshot($member);

                foreach (self::ordered($payload) as $field => $value) {
                    $rows[] = [
                        'label' => self::MEMBER_FIELDS[$field] ?? Str::headline((string) $field),
                        'old' => self::display((string) $field, $current[$field] ?? null),
                        'new' => self::display((string) $field, $value),
                    ];
                }
            }
        }

        if ($rows === [] && $request->action !== 'delete') {
            $fields = match ($request->kind()) {
                'relationship.create' => is_array($payload['member'] ?? null) ? $payload['member'] : [],
                default => $payload,
            };

            foreach (self::ordered($fields) as $field => $value) {
                $shown = self::display((string) $field, $value);

                if ($shown !== null && ! in_array($field, ['participant_ids', 'kind', 'options', 'existing_member_id', 'relative_name'], true)) {
                    $rows[] = [
                        'label' => self::MEMBER_FIELDS[$field] ?? Str::headline((string) $field),
                        'old' => null,
                        'new' => $shown,
                    ];
                }
            }
        }

        return [
            'id' => $request->id,
            'kind' => $request->kind(),
            'summary' => $request->summary,
            'status' => $request->status,
            'submitted_by' => $request->submitter->name ?? __('Former member'),
            'is_mine' => $request->submitted_by === $viewer->id,
            'created_at' => $request->created_at?->toIso8601String(),
            'reviewer' => $request->reviewer?->name,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'review_note' => $request->review_note,
            'rows' => $rows,
            'conflict' => $conflict,
            'missing' => $missing && $request->isPending(),
            'target_id' => $request->target_type === 'timeline' ? null : $request->target_id,
            'can' => [
                'review' => $this->canReview($viewer, $request, $family),
                'withdraw' => $request->isPending() && $request->submitted_by === $viewer->id,
            ],
        ];
    }

    // --- Internals ---------------------------------------------------------------------

    private function apply(ChangeRequest $request, Family $family, User $submitter, User $reviewer, bool $force): void
    {
        $payload = $request->payload;

        switch ($request->kind()) {
            case 'member.create':
                app(CreateMember::class)->handle($family, $submitter, $payload);
                break;

            case 'member.update':
                $member = $this->target($request, $family);

                if ($request->base_version !== $member->version && ! $force) {
                    throw new VersionConflictException;
                }

                app(UpdateMember::class)->handle($member, $submitter, $payload, $member->version);
                break;

            case 'member.delete':
                app(DeleteMember::class)->handle($this->target($request, $family), $reviewer);
                break;

            case 'relationship.create':
                $anchor = $this->target($request, $family);
                $existing = $payload['existing_member_id'] ?? null;

                /** @var array<string, mixed> $newPerson */
                $newPerson = (array) ($payload['member'] ?? []);

                $relative = $existing === null
                    ? $newPerson
                    : (FamilyMember::query()->inFamily($family)->whereKey((string) $existing)->first()
                        ?? throw new DomainException(__('The person to link is no longer in the tree.')));

                app(AddRelative::class)->handle($family, $submitter, $anchor, RelativeKind::from((string) $payload['kind']), $relative, (array) ($payload['options'] ?? []));
                break;

            case 'timeline.create':
                // Privacy limits are the submitter's: they cannot attach people they may not see.
                app(SaveTimelineEntry::class)->handle(new TimelineEntry, $family, $submitter, $payload, $this->privacy->viewer($submitter, $family));
                break;

            default:
                throw new DomainException(__('This kind of proposal cannot be applied.'));
        }
    }

    private function target(ChangeRequest $request, Family $family): FamilyMember
    {
        return FamilyMember::query()->inFamily($family)->find($request->target_id)
            ?? throw new DomainException(__('The person this proposal is about is no longer in the tree.'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(Family $family, User $user, string $type, string $action, ?string $targetId, array $payload, ?int $version, string $summary, string $visibility = 'family'): ChangeRequest
    {
        $request = new ChangeRequest;
        $request->family_id = $family->id;
        $request->submitted_by = $user->id;
        $request->target_type = $type;
        $request->action = $action;
        $request->target_id = $targetId;
        $request->payload = $payload;
        $request->base_version = $version;
        $request->summary = Str::limit($summary, 300);
        $request->visibility = $visibility;
        $request->save();

        $this->history->audit('change_request.submitted', $family->id, 'change_request', $request->id, null, ['summary' => $request->summary], $user);

        // Owners and Administrators are told; Editors find it in the review queue.
        $reviewers = User::query()
            ->whereIn('id', FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('status', 'active')
                ->whereIn('role_id', [FamilyRole::Owner->id(), FamilyRole::Admin->id()])
                ->select('user_id'))
            ->whereKeyNot($user->id)
            ->get();

        Notification::send($reviewers, new ChangeRequestSubmitted($request, $family, $user->name));

        return $request;
    }

    /**
     * A person's current values in the same shape the edit form submits.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(FamilyMember $member): array
    {
        return [
            'full_name' => $member->full_name,
            'nickname' => $member->nickname,
            'given_names' => $member->given_names,
            'surname' => $member->surname,
            'maiden_name' => $member->maiden_name,
            'gender' => $member->gender?->value,
            'living_status' => $member->living_status->value,
            'birth' => $member->birth->toArray(),
            'birth_place' => $member->birthPlace?->name,
            'death' => $member->death->toArray(),
            'death_place' => $member->deathPlace?->name,
            'hometown' => $member->hometown?->name,
            'nationality' => $member->nationality,
            'occupation' => $member->occupation,
            'education' => $member->education,
            'biography' => $member->biography,
            'notes' => $member->notes,
            'visibility' => $member->visibility->value,
        ];
    }

    /** A value as a reviewer reads it. Null means "empty". */
    public static function display(string $field, mixed $value): ?string
    {
        if (in_array($field, ['birth', 'death', 'event', 'start', 'end'], true)) {
            return is_array($value) ? FuzzyDate::fromInput($value)->label() : null;
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Fields in the order of the edit form. The database stores the payload as jsonb, which
     * does not keep the order the fields were submitted in.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<array-key, mixed>
     */
    private static function ordered(array $fields): array
    {
        $order = array_flip(array_keys(self::MEMBER_FIELDS));
        $position = 0;
        $rank = [];

        foreach (array_keys($fields) as $field) {
            $rank[$field] = [$order[$field] ?? count($order), $position++];
        }

        uksort($fields, fn ($a, $b): int => $rank[$a] <=> $rank[$b]);

        return $fields;
    }

    private static function stricter(string $a, string $b): string
    {
        return self::LEVELS[$a] >= self::LEVELS[$b] ? $a : $b;
    }
}

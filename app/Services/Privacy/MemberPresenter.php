<?php

namespace App\Services\Privacy;

use App\Models\FamilyMember;

/**
 * The only way a person's data leaves the server. Each method returns exactly the fields the
 * viewer may see, so a private birth date never reaches the browser, not even hidden in props.
 */
class MemberPresenter
{
    /**
     * The card shown in the tree, lists and pickers.
     *
     * @return array<string, mixed>
     */
    public function summary(FamilyMember $member, MemberViewer $viewer): array
    {
        if (! $viewer->canView($member)) {
            return self::hidden($member->id);
        }

        $sensitive = $viewer->canViewSensitive($member);

        return [
            'id' => $member->id,
            'name' => $member->full_name,
            'nickname' => $member->nickname,
            'gender' => $member->gender?->value,
            'living' => $member->isPresumedLiving(),
            'living_status' => $member->living_status->value,
            'lifespan' => $sensitive ? self::lifespan($member) : null,
            'birth_year' => $sensitive ? $member->birth->yearValue() : null,
            'is_placeholder' => $member->is_placeholder,
            'generation' => $member->generation,
            'visibility' => $member->visibility->value,
            'is_me' => $viewer->isSelf($member),
            'hidden' => false,
        ];
    }

    /**
     * Someone the viewer may not see: the tree keeps its shape, but nothing about the person
     * is revealed.
     *
     * @return array<string, mixed>
     */
    public static function hidden(string $id): array
    {
        return [
            'id' => $id,
            'name' => __('Private relative'),
            'nickname' => null,
            'gender' => null,
            'living' => true,
            'living_status' => 'unknown',
            'lifespan' => null,
            'birth_year' => null,
            'is_placeholder' => false,
            'generation' => null,
            'visibility' => null,
            'is_me' => false,
            'hidden' => true,
        ];
    }

    /**
     * The profile page. Callers must have checked `canView` first.
     *
     * @return array<string, mixed>
     */
    public function profile(FamilyMember $member, MemberViewer $viewer): array
    {
        $sensitive = $viewer->canViewSensitive($member);

        return [
            ...$this->summary($member, $viewer),
            'given_names' => $member->given_names,
            'surname' => $member->surname,
            'maiden_name' => $member->maiden_name,
            'sensitive_hidden' => ! $sensitive,
            'birth' => $sensitive ? $member->birth->toArray() : null,
            'birth_place' => $sensitive ? $member->birthPlace?->name : null,
            'death' => $member->death->toArray(),
            'death_place' => $member->deathPlace?->name,
            'hometown' => $sensitive ? $member->hometown?->name : null,
            'occupation' => $sensitive ? $member->occupation : null,
            'education' => $sensitive ? $member->education : null,
            'nationality' => $sensitive ? $member->nationality : null,
            'biography' => $sensitive ? $member->biography : null,
            'notes' => $viewer->canViewNotes($member) ? $member->notes : null,
            'version' => $member->version,
            'updated_at' => $member->updated_at?->toIso8601String(),
        ];
    }

    public static function lifespan(FamilyMember $member): ?string
    {
        $born = $member->birth->yearValue();
        $died = $member->death->yearValue();

        return match (true) {
            $born !== null && $died !== null => "{$born} – {$died}",
            $born !== null && ! $member->isPresumedLiving() => "{$born} – ?",
            $born !== null => "b. {$born}",
            $died !== null => "d. {$died}",
            default => null,
        };
    }
}

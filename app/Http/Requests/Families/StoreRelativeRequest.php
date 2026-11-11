<?php

namespace App\Http\Requests\Families;

use App\Concerns\MemberValidationRules;
use App\Enums\ParentSubtype;
use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;
use App\Enums\RelativeKind;
use App\Models\Family;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Add parent / child / partner / sibling". The relative is either `existing_member_id`
 * (someone already in this family's tree) or `member` (a new person). Authorised in the
 * controller.
 */
class StoreRelativeRequest extends FormRequest
{
    use MemberValidationRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var Family $family */
        $family = $this->route('family');

        // Only people of this family can be chosen: an id from another family does not exist here.
        $inFamily = Rule::exists('family_members', 'id')->where('family_id', $family->id)->whereNull('deleted_at');

        $partner = $this->input('kind') === RelativeKind::Partner->value;

        return [
            'kind' => ['required', Rule::enum(RelativeKind::class)],
            'existing_member_id' => ['nullable', 'uuid', $inFamily, 'required_without:member'],
            'member' => ['nullable', 'array', 'required_without:existing_member_id'],
            ...$this->memberRules('member.', nameRequired: ! $this->filled('existing_member_id')),
            'subtype' => ['nullable', Rule::enum($partner ? PartnershipSubtype::class : ParentSubtype::class)],
            'status' => ['nullable', Rule::enum(PartnershipStatus::class)],
            ...$this->fuzzyDateRules('start'),
            ...$this->fuzzyDateRules('end'),
            'place' => ['nullable', 'string', 'max:200'],
            'other_parent_id' => ['nullable', 'uuid', $inFamily],
            'shared_parent_ids' => ['nullable', 'array', 'max:10'],
            'shared_parent_ids.*' => ['uuid', $inFamily],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $member = $this->input('member');

                if (is_array($member) && ! $this->filled('existing_member_id')) {
                    $this->validateMemberDates($validator, $member, 'member.');
                }
            },
        ];
    }

    public function kind(): RelativeKind
    {
        return RelativeKind::from((string) $this->validated('kind'));
    }

    /**
     * @return array{subtype?: string|null, status?: string|null, start?: array<string, mixed>|null, end?: array<string, mixed>|null, place?: string|null, other_parent_id?: string|null, shared_parent_ids?: list<string>|null}
     */
    public function options(): array
    {
        /** @var array{subtype?: string|null, status?: string|null, start?: array<string, mixed>|null, end?: array<string, mixed>|null, place?: string|null, other_parent_id?: string|null, shared_parent_ids?: list<string>|null} */
        return $this->safe()->only(['subtype', 'status', 'start', 'end', 'place', 'other_parent_id', 'shared_parent_ids']);
    }
}

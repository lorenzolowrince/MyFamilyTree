<?php

namespace App\Http\Requests\Families;

use App\Concerns\MemberValidationRules;
use App\Enums\TimelineCategory;
use App\Enums\Visibility;
use App\Models\Family;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adding or editing a timeline entry. Authorised in the controller. */
class TimelineEntryRequest extends FormRequest
{
    use MemberValidationRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var Family $family */
        $family = $this->route('family');

        return [
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::enum(TimelineCategory::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            ...$this->fuzzyDateRules('event'),
            'place' => ['nullable', 'string', 'max:200'],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'participant_ids' => ['nullable', 'array', 'max:100'],
            'participant_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('family_members', 'id')->where('family_id', $family->id)->whereNull('deleted_at'),
            ],
        ];
    }
}

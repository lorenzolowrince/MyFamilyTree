<?php

namespace App\Http\Requests\Families;

use App\Concerns\MemberValidationRules;
use App\Enums\Certainty;
use App\Enums\ParentSubtype;
use App\Enums\PartnershipStatus;
use App\Enums\PartnershipSubtype;
use App\Models\FamilyRelationship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Editing the details of a link between two people. Authorised in the controller. */
class UpdateRelationshipRequest extends FormRequest
{
    use MemberValidationRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var FamilyRelationship $relationship */
        $relationship = $this->route('relationship');

        return [
            'subtype' => ['sometimes', Rule::enum($relationship->isPartnership() ? PartnershipSubtype::class : ParentSubtype::class)],
            'certainty' => ['sometimes', Rule::enum(Certainty::class)],
            'status' => ['sometimes', Rule::enum(PartnershipStatus::class)],
            ...$this->fuzzyDateRules('start'),
            ...$this->fuzzyDateRules('end'),
            'place' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

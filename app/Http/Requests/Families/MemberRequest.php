<?php

namespace App\Http\Requests\Families;

use App\Concerns\MemberValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Creating or editing one person. Authorised in the controller. */
class MemberRequest extends FormRequest
{
    use MemberValidationRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->memberRules(),
            // Optimistic locking: required when editing an existing person.
            'version' => [$this->isMethod('POST') ? 'nullable' : 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateMemberDates($validator, $this->all());
            },
        ];
    }

    /**
     * The member fields only.
     *
     * @return array<string, mixed>
     */
    public function memberData(): array
    {
        $data = $this->validated();
        unset($data['version']);

        return $data;
    }
}

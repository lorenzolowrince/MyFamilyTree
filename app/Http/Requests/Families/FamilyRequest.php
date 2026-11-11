<?php

namespace App\Http\Requests\Families;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Creating a family or editing its name and description. Authorised in the controller. */
class FamilyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'add_myself' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Families;

use App\Enums\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Scheduling or changing a family event. Authorised in the controller. */
class FamilyEventRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(EventType::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            // Local date and time as typed, interpreted in the person's own time zone.
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'location_text' => ['nullable', 'string', 'max:300'],
            'notify_members' => ['sometimes', 'boolean'],
        ];
    }
}

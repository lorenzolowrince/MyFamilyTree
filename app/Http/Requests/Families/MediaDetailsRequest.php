<?php

namespace App\Http\Requests\Families;

use App\Concerns\MemberValidationRules;
use App\Enums\DocumentCategory;
use App\Enums\Visibility;
use App\Models\Family;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The details of a photo or document: used when uploading (together with the files) and
 * when editing. Authorised in the controller.
 */
class MediaDetailsRequest extends FormRequest
{
    use MemberValidationRules;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var Family $family */
        $family = $this->route('family');
        $uploading = $this->isMethod('POST');

        return [
            'files' => [$uploading ? 'required' : 'prohibited', 'array', 'min:1', 'max:'.config('media.max_files_per_upload')],
            // Type and size are decided from each file's content by UploadMedia.
            'files.*' => ['file'],

            'caption' => ['sometimes', 'nullable', 'string', 'max:300'],
            'title' => ['sometimes', 'nullable', 'string', 'max:300'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...$this->fuzzyDateRules('taken'),
            ...$this->fuzzyDateRules('issued'),
            'category' => ['sometimes', Rule::enum(DocumentCategory::class)],
            'downloadable' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'album_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('photo_albums', 'id')->where('family_id', $family->id)->whereNull('deleted_at')],
            'member_ids' => ['sometimes', 'array', 'max:100'],
            'member_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('family_members', 'id')->where('family_id', $family->id)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => __('Choose at least one file.'),
            'files.max' => __('Upload at most :max files at a time.'),
            'files.*.file' => __('One of the files did not upload completely. It may be larger than the server accepts.'),
        ];
    }
}

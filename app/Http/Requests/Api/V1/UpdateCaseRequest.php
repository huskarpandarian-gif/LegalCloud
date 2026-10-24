<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('cases.write') === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'in_review', 'closed', 'archived'])],
            'categoryId' => ['sometimes', 'nullable', Rule::exists('categories', 'id')->where('organization_id', $this->user()->organization_id)],
            'ownerId' => ['sometimes', 'nullable', Rule::exists('users', 'public_id')->where('organization_id', $this->user()->organization_id)],
        ];
    }
}

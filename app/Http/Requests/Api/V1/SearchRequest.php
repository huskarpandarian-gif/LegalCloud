<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('search.use') === true;
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:1000'],
            'caseId' => ['nullable', Rule::exists('legal_cases', 'id')->where('organization_id', $this->user()->organization_id)],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $this->user()->organization_id)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'mode' => ['nullable', Rule::in(['keyword', 'semantic', 'hybrid'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

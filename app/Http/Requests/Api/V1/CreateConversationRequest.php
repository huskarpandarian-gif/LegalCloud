<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('assistant.use') === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'caseId' => ['nullable', Rule::exists('legal_cases', 'id')->where('organization_id', $this->user()->organization_id)],
        ];
    }
}

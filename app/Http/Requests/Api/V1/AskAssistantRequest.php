<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('assistant.use') === true;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:4000'],
            'caseId' => ['nullable', Rule::exists('legal_cases', 'id')->where('organization_id', $this->user()->organization_id)],
            'documentIds' => ['nullable', 'array', 'max:50'],
            'documentIds.*' => [Rule::exists('documents', 'id')->where('organization_id', $this->user()->organization_id)],
            'answerMode' => ['nullable', Rule::in(['answer', 'summary'])],
        ];
    }
}

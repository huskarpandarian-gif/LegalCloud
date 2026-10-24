<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('documents.write') === true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,doc,docx,png,jpg,jpeg,tif,tiff'],
            'caseId' => ['required', Rule::exists('legal_cases', 'id')->where('organization_id', $this->user()->organization_id)],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $this->user()->organization_id)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

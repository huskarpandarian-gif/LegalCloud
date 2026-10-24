<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('documents.write') === true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:25600', 'mimes:pdf,doc,docx,png,jpg,jpeg,tif,tiff'],
            'changeNote' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

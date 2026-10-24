<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('users.manage') === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'role' => ['sometimes', 'required', Rule::in(['administrator', 'records_manager', 'analyst', 'consultant'])],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
        ];
    }
}

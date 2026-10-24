<?php

namespace App\Http\Requests\Api\V1;

use App\Models\LegalCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('cases.write') === true;
    }

    public function rules(): array
    {
        return [
            'reference' => [
                'required',
                'string',
                'min:1',
                'max:80',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = LegalCase::query()->where('organization_id', $this->user()->organization_id)
                        ->where('reference_hash', LegalCase::referenceLookupHash((string) $value))->exists();
                    if ($exists) {
                        $fail('The reference has already been taken.');
                    }
                },
            ],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $this->user()->organization_id)],
            'ownerId' => ['nullable', Rule::exists('users', 'public_id')->where('organization_id', $this->user()->organization_id)],
        ];
    }
}

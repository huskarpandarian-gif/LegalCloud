<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['organization_id', 'document_id', 'document_version_id', 'type', 'status', 'progress', 'result_text', 'error_code', 'started_at', 'completed_at'])]
class ProcessingJob extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'result_encryption_version' => 'integer',
        ];
    }

    protected function resultText(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null
                ? null
                : ((int) $this->result_encryption_version >= 1 ? Crypt::decryptString($value) : $value),
            set: fn (?string $value): array => [
                'result_text' => $value === null ? null : Crypt::encryptString($value),
                'result_encryption_version' => $value === null ? 0 : 1,
            ],
        );
    }
}

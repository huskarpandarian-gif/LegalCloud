<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['document_id', 'version_number', 'file_name', 'disk', 'path', 'mime_type', 'size_bytes', 'checksum', 'change_note', 'uploaded_by', 'extracted_text', 'file_encryption_version'])]
class DocumentVersion extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size_bytes' => 'integer',
            'file_encryption_version' => 'integer',
            'content_encryption_version' => 'integer',
        ];
    }

    protected function extractedText(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null
                ? null
                : ((int) $this->content_encryption_version >= 1 ? Crypt::decryptString($value) : $value),
            set: fn (?string $value): array => [
                'extracted_text' => $value === null ? null : Crypt::encryptString($value),
                'content_encryption_version' => $value === null ? 0 : 1,
            ],
        );
    }

    protected function fileName(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->file_name_encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'file_name' => Crypt::encryptString(basename($value)),
                'file_name_encryption_version' => 1,
            ],
        );
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'public_id');
    }
}

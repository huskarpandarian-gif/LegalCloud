<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['document_version_id', 'chunk_index', 'page', 'content'])]
class DocumentChunk extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['chunk_index' => 'integer', 'page' => 'integer', 'encryption_version' => 'integer'];
    }

    protected function content(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'content' => Crypt::encryptString($value),
                'encryption_version' => 1,
            ],
        );
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['assistant_message_id', 'reference_number', 'document_id', 'document_version_id', 'page', 'excerpt', 'relevance'])]
class Citation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['page' => 'integer', 'relevance' => 'float', 'encryption_version' => 'integer'];
    }

    protected function excerpt(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'excerpt' => Crypt::encryptString($value),
                'encryption_version' => 1,
            ],
        );
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

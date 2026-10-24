<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['organization_id', 'case_id', 'category_id', 'current_version_id', 'title', 'description', 'mime_type', 'size_bytes', 'status'])]
class Document extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'title_encryption_version' => 'integer',
            'description_encryption_version' => 'integer',
        ];
    }

    protected function title(): Attribute
    {
        return $this->encryptedTextAttribute('title', 'title_encryption_version');
    }

    protected function description(): Attribute
    {
        return $this->encryptedTextAttribute('description', 'description_encryption_version');
    }

    private function encryptedTextAttribute(string $field, string $versionField): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : ((int) $this->{$versionField} >= 1 ? Crypt::decryptString($value) : $value),
            set: fn (?string $value): array => [
                $field => $value === null ? null : Crypt::encryptString($value),
                $versionField => $value === null ? 0 : 1,
            ],
        );
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }
}

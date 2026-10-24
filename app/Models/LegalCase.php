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

#[Fillable(['organization_id', 'category_id', 'owner_id', 'reference', 'reference_hash', 'title', 'description', 'status'])]
class LegalCase extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'reference_encryption_version' => 'integer',
            'title_encryption_version' => 'integer',
            'description_encryption_version' => 'integer',
        ];
    }

    protected function reference(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->reference_encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'reference' => Crypt::encryptString(trim($value)),
                'reference_hash' => self::referenceLookupHash($value),
                'reference_encryption_version' => 1,
            ],
        );
    }

    protected function title(): Attribute
    {
        return $this->encryptedTextAttribute('title', 'title_encryption_version');
    }

    protected function description(): Attribute
    {
        return $this->encryptedTextAttribute('description', 'description_encryption_version');
    }

    public static function referenceLookupHash(string $reference): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($reference)), (string) config('app.key'));
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }
}

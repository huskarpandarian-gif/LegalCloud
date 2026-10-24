<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['organization_id', 'user_id', 'case_id', 'title'])]
class Conversation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['title_encryption_version' => 'integer'];
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->title_encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'title' => Crypt::encryptString($value),
                'title_encryption_version' => 1,
            ],
        );
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

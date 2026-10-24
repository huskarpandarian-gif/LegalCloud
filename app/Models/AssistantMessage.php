<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['conversation_id', 'role', 'content', 'status', 'error_code'])]
class AssistantMessage extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['content_encryption_version' => 'integer'];
    }

    protected function content(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->content_encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'content' => Crypt::encryptString($value),
                'content_encryption_version' => 1,
            ],
        );
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class)->orderBy('reference_number');
    }
}

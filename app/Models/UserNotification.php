<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['organization_id', 'user_id', 'type', 'title', 'body', 'resource_type', 'resource_id', 'read_at'])]
class UserNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'body_encryption_version' => 'integer'];
    }

    protected function body(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null
                ? null
                : ((int) $this->body_encryption_version >= 1 ? Crypt::decryptString($value) : $value),
            set: fn (?string $value): array => [
                'body' => $value === null ? null : Crypt::encryptString($value),
                'body_encryption_version' => $value === null ? 0 : 1,
            ],
        );
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'public_id');
    }
}

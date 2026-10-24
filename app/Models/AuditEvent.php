<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['organization_id', 'actor_id', 'action', 'resource_type', 'resource_id', 'changes', 'ip_address', 'occurred_at'])]
class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected function changes(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?array {
                $encrypted = $this->attributes['encrypted_changes'] ?? null;
                if ($encrypted !== null) {
                    return json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
                }

                return $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            },
            set: fn (?array $value): array => [
                'changes' => null,
                'encrypted_changes' => $value === null ? null : Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)),
            ],
        );
    }

    protected function ipAddress(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $this->attributes['encrypted_ip_address'] !== null
                ? Crypt::decryptString($this->attributes['encrypted_ip_address'])
                : $value,
            set: fn (?string $value): array => [
                'ip_address' => null,
                'encrypted_ip_address' => $value === null ? null : Crypt::encryptString($value),
                'ip_encryption_version' => $value === null ? 0 : 1,
            ],
        );
    }
}

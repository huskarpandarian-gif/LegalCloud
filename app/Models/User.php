<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Identity\RoleCatalog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'name', 'email', 'password', 'role', 'status'])]
#[Hidden(['password', 'remember_token', 'email_hash', 'name_encryption_version', 'email_encryption_version'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->public_id ??= (string) Str::uuid();
            $user->role ??= 'consultant';
            $user->status ??= 'active';
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'name_encryption_version' => 'integer',
            'email_encryption_version' => 'integer',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->name_encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'name' => Crypt::encryptString(trim($value)),
                'name_encryption_version' => 1,
            ],
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => (int) $this->email_encryption_version >= 1 ? Crypt::decryptString($value) : $value,
            set: fn (string $value): array => [
                'email' => Crypt::encryptString(mb_strtolower(trim($value))),
                'email_hash' => self::emailLookupHash($value),
                'email_encryption_version' => 1,
            ],
        );
    }

    public static function emailLookupHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function allows(string $permission): bool
    {
        return app(RoleCatalog::class)->allows($this, $permission);
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return app(RoleCatalog::class)->permissionsFor($this);
    }
}

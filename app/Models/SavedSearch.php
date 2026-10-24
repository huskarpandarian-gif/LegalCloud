<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['organization_id', 'user_id', 'name', 'query', 'filters'])]
class SavedSearch extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'name_encryption_version' => 'integer',
            'query_encryption_version' => 'integer',
        ];
    }

    protected function name(): Attribute
    {
        return $this->encrypted('name', 'name_encryption_version');
    }

    protected function query(): Attribute
    {
        return $this->encrypted('query', 'query_encryption_version');
    }

    private function encrypted(string $field, string $versionField): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : ((int) $this->{$versionField} >= 1 ? Crypt::decryptString($value) : $value),
            set: fn (?string $value): array => [
                $field => $value === null ? null : Crypt::encryptString($value),
                $versionField => $value === null ? 0 : 1,
            ],
        );
    }
}

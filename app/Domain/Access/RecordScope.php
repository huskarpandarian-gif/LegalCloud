<?php

namespace App\Domain\Access;

use App\Models\Document;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RecordScope
{
    /**
     * The access rule that exists today is the organization boundary.
     * case_participants stores assignments, but nothing in the application
     * uses that table to hide an expediente. Limiting visibility to participants
     * is a pending business decision and is not applied here.
     *
     * @return Builder<Document>
     */
    public function documents(User $user): Builder
    {
        return Document::query()->where('organization_id', $user->organization_id);
    }

    /**
     * @return Builder<LegalCase>
     */
    public function cases(User $user): Builder
    {
        return LegalCase::query()->where('organization_id', $user->organization_id);
    }

    /**
     * Drop filters that point outside the caller's visible records.
     * A null result means the caller asked only for records they cannot see,
     * so the search must come back empty instead of confirming those ids.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function narrow(User $user, array $filters): ?array
    {
        if (! empty($filters['caseId']) && ! $this->cases($user)->whereKey($filters['caseId'])->exists()) {
            return null;
        }

        if (array_key_exists('documentIds', $filters) && is_array($filters['documentIds'])) {
            $filters['documentIds'] = $this->documents($user)
                ->whereIn('id', $filters['documentIds'])
                ->pluck('id')
                ->all();

            if ($filters['documentIds'] === []) {
                return null;
            }
        }

        return $filters;
    }
}

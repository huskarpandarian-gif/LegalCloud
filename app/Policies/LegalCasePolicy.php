<?php

namespace App\Policies;

use App\Models\LegalCase;
use App\Models\User;

class LegalCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows('cases.read');
    }

    public function view(User $user, LegalCase $case): bool
    {
        return $this->viewAny($user) && $case->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->allows('cases.write');
    }

    public function update(User $user, LegalCase $case): bool
    {
        return $user->allows('cases.write') && $case->organization_id === $user->organization_id;
    }
}

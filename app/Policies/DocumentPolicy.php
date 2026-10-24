<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows('documents.read');
    }

    public function view(User $user, Document $document): bool
    {
        return $this->viewAny($user) && $document->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->allows('documents.write');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->allows('documents.write') && $document->organization_id === $user->organization_id;
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function process(User $user, Document $document): bool
    {
        return $user->allows('documents.process') && $document->organization_id === $user->organization_id;
    }
}

<?php

namespace App\Policies;

use App\Models\DocumentVersion;
use App\Models\User;

class DocumentVersionPolicy
{
    public function view(User $user, DocumentVersion $version): bool
    {
        $document = $version->document;

        return $document !== null && $user->can('view', $document);
    }

    public function download(User $user, DocumentVersion $version): bool
    {
        $document = $version->document;

        return $document !== null && $user->can('download', $document);
    }

    public function create(User $user, DocumentVersion $version): bool
    {
        $document = $version->document;

        return $document !== null && $user->can('update', $document);
    }
}

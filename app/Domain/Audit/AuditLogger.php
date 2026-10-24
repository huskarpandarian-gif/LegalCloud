<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(
        Request $request,
        User $actor,
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?array $changes = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'organization_id' => $actor->organization_id,
            'actor_id' => $actor->public_id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'changes' => $changes,
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actorId' => $this->actor_id,
            'action' => $this->action,
            'resourceType' => $this->resource_type,
            'resourceId' => $this->resource_id,
            'changes' => $this->changes,
            'ipAddress' => $this->ip_address,
            'occurredAt' => $this->occurred_at,
        ];
    }
}

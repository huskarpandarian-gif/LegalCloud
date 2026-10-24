<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        return AuditEventResource::collection(AuditEvent::query()->where('organization_id', $request->user()->organization_id)
            ->when($request->query('actorId'), fn ($query, $id) => $query->where('actor_id', $id))
            ->when($request->query('resourceType'), fn ($query, $type) => $query->where('resource_type', $type))
            ->when($request->query('resourceId'), fn ($query, $id) => $query->where('resource_id', $id))
            ->when($request->query('from'), fn ($query, $date) => $query->where('occurred_at', '>=', $date))
            ->when($request->query('to'), fn ($query, $date) => $query->where('occurred_at', '<=', $date))
            ->orderByDesc('occurred_at')->paginate(min(max((int) $request->query('perPage', 20), 1), 100)));
    }
}

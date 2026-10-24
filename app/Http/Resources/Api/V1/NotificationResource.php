<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'resourceType' => $this->resource_type,
            'resourceId' => $this->resource_id,
            'readAt' => $this->read_at,
            'createdAt' => $this->created_at,
        ];
    }
}

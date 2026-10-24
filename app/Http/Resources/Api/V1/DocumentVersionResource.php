<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'versionNumber' => $this->version_number,
            'fileName' => $this->file_name,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'checksum' => $this->checksum,
            'changeNote' => $this->change_note,
            'uploadedBy' => $this->uploaded_by,
            'createdAt' => $this->created_at,
        ];
    }
}

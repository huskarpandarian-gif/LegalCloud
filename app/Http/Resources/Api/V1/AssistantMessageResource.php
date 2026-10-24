<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssistantMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversationId' => $this->conversation_id,
            'role' => $this->role,
            'content' => $this->content,
            'status' => $this->status,
            'citations' => $this->whenLoaded('citations', function () use ($request) {
                $user = $request->user();

                return $this->citations->filter(function ($citation) use ($user) {
                    $document = $citation->document;

                    return $user && $document && $document->organization_id === $user->organization_id;
                })->values()->map(fn ($citation) => [
                    'reference' => $citation->reference_number,
                    'documentId' => $citation->document_id,
                    'versionId' => $citation->document_version_id,
                    'title' => $citation->document?->title,
                    'page' => $citation->page,
                    'excerpt' => $citation->excerpt,
                    'relevance' => (float) $citation->relevance,
                ]);
            }),
            'createdAt' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'progress' => $this->progress,
            'resultText' => $this->when(
                $request->user()?->allows('documents.process') === true && $request->user()?->allows('documents.read') === true,
                $this->result_text,
            ),
            'errorCode' => $this->error_code,
            'message' => $this->publicMessage(),
            'createdAt' => $this->created_at,
            'completedAt' => $this->completed_at,
        ];
    }

    private function publicMessage(): ?string
    {
        return match ($this->error_code) {
            'ocr_provider_unavailable' => 'Este archivo no tiene texto extraíble con el procesador actual.',
            'ocr_no_text' => 'No se encontró texto extraíble. Puedes reintentar la extracción.',
            'ocr_processing_failed' => 'La extracción no terminó. Puedes reintentarla.',
            'embedding_provider_unavailable', 'vector_store_disabled' => 'El texto quedó extraído, pero la indexación semántica no está disponible.',
            'embedding_provider_failed', 'embedding_processing_failed' => 'La indexación semántica no terminó. El texto extraído sigue disponible.',
            default => $this->status === 'failed' ? 'El procesamiento no terminó. Puedes reintentarlo.' : null,
        };
    }
}

<?php

namespace App\Domain\Documents;

use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Builder;

class DocumentTextSearch
{
    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function search(string $term, string $organizationId, array $filters, int $page, int $perPage): array
    {
        $hits = [];
        $metadataDocuments = $this->filteredDocuments($organizationId, $filters)
            ->with('currentVersion')
            ->orderBy('id')->limit(5000)
            ->get();

        foreach ($metadataDocuments as $document) {
            $text = $document->description ?: $document->title;
            if ($text !== '' && mb_stripos($text, $term) !== false) {
                $hits[$document->id] = $this->hit($document, $text, 0.65, null);
            }
        }

        // Chunks are decrypted by their model accessor only after tenant/version filtering in SQL.
        $chunks = DocumentChunk::query()
            ->with('version.document.currentVersion')
            ->whereHas('version.document', function (Builder $documents) use ($organizationId, $filters): void {
                $documents->where('organization_id', $organizationId)
                    ->whereColumn('documents.current_version_id', 'document_versions.id')
                    ->when($filters['caseId'] ?? null, fn (Builder $query, string $id) => $query->where('case_id', $id))
                    ->when($filters['categoryId'] ?? null, fn (Builder $query, string $id) => $query->where('category_id', $id))
                    ->when($filters['documentIds'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('documents.id', $ids))
                    ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('documents.created_at', '>=', $date))
                    ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('documents.created_at', '<=', $date));
            })
            ->orderBy('id')->limit(5000)->get();

        foreach ($chunks as $chunk) {
            $document = $chunk->version?->document;
            if (! $document || mb_stripos($chunk->content, $term) === false) {
                continue;
            }

            $position = mb_stripos($chunk->content, $term);
            $excerpt = mb_substr($chunk->content, max(0, $position - 100), 280);
            $hits[$document->id] = $this->hit($document, $excerpt, 0.9, $chunk->page);
        }

        $results = collect(array_values($hits))->sortByDesc('score')->values();
        $total = $results->count();

        return [
            'data' => $results->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    private function filteredDocuments(string $organizationId, array $filters): Builder
    {
        return Document::query()->where('organization_id', $organizationId)
            ->when($filters['caseId'] ?? null, fn (Builder $query, string $id) => $query->where('case_id', $id))
            ->when($filters['categoryId'] ?? null, fn (Builder $query, string $id) => $query->where('category_id', $id))
            ->when($filters['documentIds'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('id', $ids))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date));
    }

    private function hit(Document $document, string $excerpt, float $score, ?int $page): array
    {
        return [
            'document' => (new DocumentResource($document))->resolve(),
            'score' => $score,
            'excerpt' => $excerpt,
            'page' => $page,
        ];
    }
}

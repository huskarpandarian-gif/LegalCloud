<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\LlmProvider;
use App\Domain\Documents\ReciprocalRankFusion;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Builder;

class RagAnswerService
{
    public function __construct(
        private readonly LlmProvider $llm,
        private readonly VectorIndexService $vectors,
    ) {}

    /** @return array{content: string, citations: list<array<string, mixed>>} */
    public function answer(string $question, string $organizationId, array $filters = [], string $mode = 'answer'): array
    {
        $sources = $this->onlyAuthorized($this->retrieve($question, $organizationId, $filters), $organizationId);
        if ($sources === []) {
            return [
                'content' => 'No encontré fragmentos indexados y autorizados que permitan responder esta pregunta.',
                'citations' => [],
            ];
        }

        return [
            'content' => $this->llm->answer($question, $sources, $mode),
            'citations' => array_map(fn (array $source) => $source['citation'], $sources),
        ];
    }

    /** @return list<array{content: string, citation: array<string, mixed>}> */
    private function retrieve(string $question, string $organizationId, array $filters): array
    {
        $lexical = $this->lexicalPassages($question, $organizationId, $filters);
        if (! config('legalcloud.vector.enabled')) {
            return $this->finalize($lexical);
        }

        $semantic = $this->semanticPassages($question, $organizationId, $filters);
        if ($semantic === []) {
            return $this->finalize($lexical);
        }
        if ($lexical === []) {
            return $this->finalize($semantic);
        }

        return $this->finalize(ReciprocalRankFusion::fuse(
            [$semantic, $lexical],
            fn (array $hit): string => implode('|', [
                (string) ($hit['citation']['documentId'] ?? ''),
                (string) ($hit['citation']['page'] ?? ''),
                md5((string) ($hit['citation']['excerpt'] ?? '')),
            ]),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function semanticPassages(string $question, string $organizationId, array $filters): array
    {
        $results = $this->vectors->search($question, $organizationId, $filters, 1, 20, false);

        return array_values(array_filter(array_map(function (array $hit): ?array {
            $document = $hit['document'];
            $version = $document['currentVersion'] ?? null;
            if ($version instanceof \Illuminate\Http\Resources\Json\JsonResource) {
                $version = $version->resolve();
            }
            if (! is_array($version) || ! isset($version['id'], $document['id'])) {
                return null;
            }

            $excerpt = (string) $hit['excerpt'];

            return [
                'content' => mb_substr($excerpt, 0, 2400),
                'score' => (float) $hit['score'],
                'citation' => [
                    'documentId' => $document['id'],
                    'versionId' => $version['id'],
                    'title' => $document['title'],
                    'page' => $hit['page'],
                    'excerpt' => mb_substr($excerpt, 0, 800),
                    'relevance' => (float) $hit['score'],
                ],
            ];
        }, $results['data'])));
    }

    /** @return list<array<string, mixed>> */
    private function lexicalPassages(string $question, string $organizationId, array $filters): array
    {
        $terms = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [])
            ->filter(fn (string $term) => mb_strlen($term) >= 3)
            ->unique()->take(10)->values();

        if ($terms->isEmpty()) {
            return [];
        }

        $chunks = DocumentChunk::query()
            ->with('version.document')
            ->whereHas('version.document', function (Builder $documents) use ($organizationId, $filters): void {
                $documents->where('organization_id', $organizationId)
                    ->whereColumn('documents.current_version_id', 'document_versions.id')
                    ->when($filters['caseId'] ?? null, fn (Builder $query, string $caseId) => $query->where('case_id', $caseId))
                    ->when($filters['documentIds'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('documents.id', $ids));
            })
            ->orderBy('id')->limit(5000)->get();

        return $chunks->map(function (DocumentChunk $chunk) use ($terms): ?array {
            $version = $chunk->version;
            $document = $version?->document;
            if (! $version || ! $document) {
                return null;
            }

            $content = $chunk->content;
            $matches = $terms->filter(fn (string $term) => mb_stripos($content, $term) !== false)->count();
            if ($matches === 0) {
                return null;
            }

            $score = $matches / max($terms->count(), 1);

            return [
                'content' => mb_substr($content, 0, 2400),
                'score' => $score,
                'citation' => [
                    'documentId' => $document->id,
                    'versionId' => $version->id,
                    'title' => $document->title,
                    'page' => $chunk->page,
                    'excerpt' => mb_substr($content, 0, 800),
                    'relevance' => $score,
                ],
            ];
        })->filter()->sortByDesc('score')->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function onlyAuthorized(array $sources, string $organizationId): array
    {
        $ids = collect($sources)->map(fn (array $source) => $source['citation']['documentId'] ?? null)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $allowed = Document::query()->where('organization_id', $organizationId)->whereIn('id', $ids)->pluck('id')->all();

        return array_values(array_filter(
            $sources,
            fn (array $source): bool => in_array($source['citation']['documentId'] ?? '', $allowed, true),
        ));
    }

    private function finalize(array $sources): array
    {
        return collect($sources)->sortByDesc('score')->take(5)->values()->map(function (array $source, int $index): array {
            $source['citation']['reference'] = $index + 1;
            $source['citation']['relevance'] = round((float) ($source['score'] ?? $source['citation']['relevance'] ?? 0), 4);
            unset($source['score']);

            return $source;
        })->all();
    }
}

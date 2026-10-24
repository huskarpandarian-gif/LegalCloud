<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\EmbeddingProvider;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VectorIndexService
{
    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    public function forgetVersion(string $documentVersionId): void
    {
        if (! config('legalcloud.vector.enabled')) {
            return;
        }

        DB::connection(config('legalcloud.vector.connection'))
            ->delete('DELETE FROM document_vectors WHERE document_version_id = ?', [$documentVersionId]);
    }

    public function index(DocumentChunk $chunk): void
    {
        if (! config('legalcloud.vector.enabled')) {
            return;
        }

        $version = $chunk->version()->with('document')->firstOrFail();
        $document = $version->document;
        if (! $document || trim($chunk->content) === '') {
            return;
        }

        $dimensions = (int) config('legalcloud.vector.dimensions');
        $embedding = VectorValue::toPgVector($this->embeddings->embed($chunk->content), $dimensions);
        $connection = DB::connection(config('legalcloud.vector.connection'));

        // Keep vectors in PostgreSQL; MySQL remains the source of truth for documents and permissions.
        $connection->statement(
            <<<'SQL'
                INSERT INTO document_vectors (
                    id, organization_id, document_id, document_version_id, case_id, category_id,
                    chunk_index, page, content, content_encryption_version, embedding, embedding_model, document_created_at,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?::vector, ?, ?, ?, ?)
                ON CONFLICT (document_version_id, chunk_index) DO UPDATE SET
                    organization_id = EXCLUDED.organization_id,
                    document_id = EXCLUDED.document_id,
                    case_id = EXCLUDED.case_id,
                    category_id = EXCLUDED.category_id,
                    page = EXCLUDED.page,
                    content = EXCLUDED.content,
                    content_encryption_version = EXCLUDED.content_encryption_version,
                    embedding = EXCLUDED.embedding,
                    embedding_model = EXCLUDED.embedding_model,
                    document_created_at = EXCLUDED.document_created_at,
                    updated_at = EXCLUDED.updated_at
                SQL,
            [
                (string) Str::uuid(),
                $document->organization_id,
                $document->id,
                $version->id,
                $document->case_id,
                $document->category_id,
                $chunk->chunk_index,
                $chunk->page,
                Crypt::encryptString($chunk->content),
                $embedding,
                config('legalcloud.vector.embedding_model'),
                $document->created_at,
                now(),
                now(),
            ],
        );
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function search(string $query, string $organizationId, array $filters, int $page, int $perPage, bool $bestChunkPerDocument = true): array
    {
        if (! config('legalcloud.vector.enabled')) {
            throw new ProviderNotConfigured('PostgreSQL vector search is disabled.');
        }

        $dimensions = (int) config('legalcloud.vector.dimensions');
        $embedding = VectorValue::toPgVector($this->embeddings->embed($query), $dimensions);
        $where = ['vectors.organization_id = ?'];
        $bindings = [$embedding, $organizationId];

        foreach (['caseId' => 'case_id', 'categoryId' => 'category_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $where[] = "vectors.{$column} = ?";
                $bindings[] = $filters[$filter];
            }
        }
        if (! empty($filters['documentIds'])) {
            $documentIds = array_values($filters['documentIds']);
            $where[] = 'vectors.document_id IN ('.implode(', ', array_fill(0, count($documentIds), '?')).')';
            array_push($bindings, ...$documentIds);
        }
        if (isset($filters['from'])) {
            $where[] = 'vectors.document_created_at >= ?';
            $bindings[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $where[] = 'vectors.document_created_at < (?::date + INTERVAL \'1 day\')';
            $bindings[] = $filters['to'];
        }

        $bindings[] = $perPage;
        $bindings[] = ($page - 1) * $perPage;
        $rankFilter = $bestChunkPerDocument ? 'WHERE document_rank = 1' : '';
        $rows = DB::connection(config('legalcloud.vector.connection'))->select(
            'WITH query_embedding AS (SELECT ?::vector AS value),
            ranked_matches AS (
                SELECT vectors.document_id, vectors.document_version_id, vectors.content AS excerpt,
                    vectors.content_encryption_version,
                    vectors.page, 1 - (vectors.embedding <=> query_embedding.value) AS score,
                    vectors.embedding <=> query_embedding.value AS distance,
                    ROW_NUMBER() OVER (
                        PARTITION BY vectors.document_id
                        ORDER BY vectors.embedding <=> query_embedding.value
                    ) AS document_rank
                FROM document_vectors AS vectors CROSS JOIN query_embedding
                WHERE '.implode(' AND ', $where).'
            ),
            best_matches AS (
                SELECT document_id, document_version_id, excerpt, content_encryption_version, page, score, distance,
                    COUNT(*) OVER() AS total_count
                FROM ranked_matches
                '.$rankFilter.'
            )
            SELECT document_id, document_version_id, excerpt, content_encryption_version, page, score, total_count
            FROM best_matches
            ORDER BY distance
            LIMIT ? OFFSET ?',
            $bindings,
        );

        $documents = Document::query()->where('organization_id', $organizationId)
            ->whereIn('id', collect($rows)->pluck('document_id')->unique())
            ->with('currentVersion')->get()->keyBy('id');
        $hits = [];

        foreach ($rows as $row) {
            $document = $documents->get($row->document_id);
            if (! $document || $document->current_version_id !== $row->document_version_id) {
                continue;
            }

            $excerpt = (int) $row->content_encryption_version >= 1
                ? Crypt::decryptString($row->excerpt)
                : $row->excerpt;
            $hits[] = [
                'document' => (new DocumentResource($document))->resolve(),
                'score' => (float) $row->score,
                'excerpt' => $excerpt,
                'page' => $row->page === null ? null : (int) $row->page,
            ];
        }

        return [
            'data' => $hits,
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => (int) ($rows[0]->total_count ?? 0),
                'lastPage' => max(1, (int) ceil(((int) ($rows[0]->total_count ?? 0)) / $perPage)),
            ],
        ];
    }
}

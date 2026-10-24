<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProvisionVectorStore extends Command
{
    protected $signature = 'legalcloud:vector-migrate';

    protected $description = 'Enable pgvector and create the PostgreSQL vector index table';

    public function handle(): int
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->error('The PHP pdo_pgsql extension is required for the vector database connection.');

            return self::FAILURE;
        }

        $dimensions = (int) config('legalcloud.vector.dimensions');
        if ($dimensions < 1 || $dimensions > 2000) {
            $this->error('VECTOR_EMBEDDING_DIMENSIONS must be between 1 and 2000.');

            return self::FAILURE;
        }

        try {
            $connection = DB::connection(config('legalcloud.vector.connection'));
            $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
            $connection->statement("CREATE TABLE IF NOT EXISTS document_vectors (
                id uuid PRIMARY KEY,
                organization_id uuid NOT NULL,
                document_id uuid NOT NULL,
                document_version_id uuid NOT NULL,
                case_id uuid NOT NULL,
                category_id uuid NULL,
                chunk_index integer NOT NULL,
                page integer NULL,
                content text NOT NULL,
                content_encryption_version smallint NOT NULL DEFAULT 0,
                embedding vector({$dimensions}) NOT NULL,
                embedding_model varchar(120) NOT NULL,
                document_created_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (document_version_id, chunk_index)
            )");
            $connection->statement('ALTER TABLE document_vectors ADD COLUMN IF NOT EXISTS content_encryption_version smallint NOT NULL DEFAULT 0');
            $connection->statement('CREATE INDEX IF NOT EXISTS document_vectors_embedding_hnsw ON document_vectors USING hnsw (embedding vector_cosine_ops)');
            $connection->statement('CREATE INDEX IF NOT EXISTS document_vectors_organization_case_idx ON document_vectors (organization_id, case_id)');
            $connection->statement('CREATE INDEX IF NOT EXISTS document_vectors_organization_category_idx ON document_vectors (organization_id, category_id)');
        } catch (Throwable $exception) {
            $this->error('Vector store provisioning failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("pgvector is ready with {$dimensions} embedding dimensions.");

        return self::SUCCESS;
    }
}

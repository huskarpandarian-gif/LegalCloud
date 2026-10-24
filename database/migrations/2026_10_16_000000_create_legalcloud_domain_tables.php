<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 180);
            $table->string('slug', 180)->unique();
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->foreignUuid('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role', 32)->default('consultant')->index();
            $table->string('status', 24)->default('active')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('color', 7)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('legal_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('owner_id')->nullable()->index();
            $table->string('reference', 80);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status', 'updated_at']);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('current_version_id')->nullable()->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 24)->default('uploaded')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'case_id', 'created_at']);
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('file_name', 255);
            $table->string('disk', 50)->default('private');
            $table->string('path', 512);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->string('change_note', 1000)->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->references('public_id')->on('users')->nullOnDelete();
            $table->longText('extracted_text')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'version_number']);
        });

        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('page')->nullable();
            $table->longText('content');
            $table->json('embedding')->nullable();
            $table->string('embedding_model', 120)->nullable();
            $table->timestamps();
            $table->unique(['document_version_id', 'chunk_index']);
        });

        Schema::create('processing_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->longText('result_text')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('case_id')->nullable()->constrained('legal_cases')->nullOnDelete();
            $table->string('title', 160);
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'updated_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->string('status', 24)->default('completed');
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('citations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assistant_message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page')->nullable();
            $table->text('excerpt');
            $table->decimal('relevance', 5, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->references('public_id')->on('users')->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 180);
            $table->text('body')->nullable();
            $table->string('resource_type', 80)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'read_at', 'created_at']);
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->references('public_id')->on('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('resource_type', 80);
            $table->uuid('resource_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->index(['organization_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('citations');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('processing_jobs');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('legal_cases');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('personal_access_tokens');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropUnique(['public_id']);
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn(['public_id', 'role', 'status']);
        });
        Schema::dropIfExists('organizations');
    }
};

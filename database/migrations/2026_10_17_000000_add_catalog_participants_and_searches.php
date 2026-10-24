<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->json('role_permissions')->nullable();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('active')->default(true);
        });

        Schema::create('case_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('public_id')->on('users')->cascadeOnDelete();
            $table->uuid('assigned_by')->nullable();
            $table->timestamps();
            $table->unique(['case_id', 'user_id']);
        });

        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->unsignedTinyInteger('name_encryption_version')->default(0);
            $table->text('query');
            $table->unsignedTinyInteger('query_encryption_version')->default(0);
            $table->json('filters')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('case_participants');
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('role_permissions');
        });
    }
};

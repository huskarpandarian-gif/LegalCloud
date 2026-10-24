<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->dropColumn(['embedding', 'embedding_model']);
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->json('embedding')->nullable();
            $table->string('embedding_model', 120)->nullable();
        });
    }
};

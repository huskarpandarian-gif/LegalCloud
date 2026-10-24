<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('file_encryption_version')->default(0);
            $table->unsignedTinyInteger('content_encryption_version')->default(0);
        });

        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('encryption_version')->default(0);
        });

        Schema::table('assistant_messages', function (Blueprint $table): void {
            $table->unsignedTinyInteger('content_encryption_version')->default(0);
        });

        Schema::table('citations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('encryption_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('citations', function (Blueprint $table): void {
            $table->dropColumn('encryption_version');
        });
        Schema::table('assistant_messages', function (Blueprint $table): void {
            $table->dropColumn('content_encryption_version');
        });
        Schema::table('document_chunks', function (Blueprint $table): void {
            $table->dropColumn('encryption_version');
        });
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn(['file_encryption_version', 'content_encryption_version']);
        });
    }
};

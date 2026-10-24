<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->longText('encrypted_changes')->nullable();
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->unsignedTinyInteger('body_encryption_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('body_encryption_version');
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('encrypted_changes');
        });
    }
};

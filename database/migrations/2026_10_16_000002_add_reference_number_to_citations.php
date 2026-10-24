<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reference_number')->default(1)->after('assistant_message_id');
            $table->unique(['assistant_message_id', 'reference_number']);
        });
    }

    public function down(): void
    {
        Schema::table('citations', function (Blueprint $table): void {
            $table->dropForeign(['assistant_message_id']);
        });

        Schema::table('citations', function (Blueprint $table): void {
            $table->dropUnique(['assistant_message_id', 'reference_number']);
            $table->dropColumn('reference_number');
        });

        Schema::table('citations', function (Blueprint $table): void {
            $table->foreign('assistant_message_id')->references('id')->on('assistant_messages')->cascadeOnDelete();
        });
    }
};

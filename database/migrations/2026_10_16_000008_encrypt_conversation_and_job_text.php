<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('title_encryption_version')->default(0);
        });

        Schema::table('processing_jobs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('result_encryption_version')->default(0);
        });
    }

    public function down(): void
    {
        DB::table('conversations')->where('title_encryption_version', 1)->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('conversations')->where('id', $row->id)->update(['title' => Crypt::decryptString($row->title)]);
            }
        });
        DB::table('processing_jobs')->where('result_encryption_version', 1)->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('processing_jobs')->where('id', $row->id)->update(['result_text' => Crypt::decryptString($row->result_text)]);
            }
        });

        Schema::table('processing_jobs', function (Blueprint $table): void {
            $table->dropColumn('result_encryption_version');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('title_encryption_version');
        });
    }
};

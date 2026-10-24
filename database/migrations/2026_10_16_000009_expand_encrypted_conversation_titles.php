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
            $table->text('title')->change();
        });
    }

    public function down(): void
    {
        DB::table('conversations')->where('title_encryption_version', 1)->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('conversations')->where('id', $row->id)->update([
                    'title' => Crypt::decryptString($row->title),
                    'title_encryption_version' => 0,
                ]);
            }
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('title', 160)->change();
        });
    }
};

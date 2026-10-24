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
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
            $table->text('name')->change();
            $table->text('email')->change();
            $table->char('email_hash', 64)->nullable()->unique();
            $table->unsignedTinyInteger('name_encryption_version')->default(0);
            $table->unsignedTinyInteger('email_encryption_version')->default(0);
        });
    }

    public function down(): void
    {
        DB::table('users')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'name' => (int) $user->name_encryption_version >= 1 ? Crypt::decryptString($user->name) : $user->name,
                    'email' => (int) $user->email_encryption_version >= 1 ? Crypt::decryptString($user->email) : $user->email,
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email_hash']);
            $table->dropColumn(['email_hash', 'name_encryption_version', 'email_encryption_version']);
            $table->string('name')->change();
            $table->string('email')->unique()->change();
        });
    }
};

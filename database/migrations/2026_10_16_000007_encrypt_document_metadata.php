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
        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropUnique('legal_cases_organization_id_reference_unique');
            $table->text('reference')->change();
            $table->text('title')->change();
            $table->text('description')->nullable()->change();
            $table->char('reference_hash', 64)->nullable();
            $table->unsignedTinyInteger('reference_encryption_version')->default(0);
            $table->unsignedTinyInteger('title_encryption_version')->default(0);
            $table->unsignedTinyInteger('description_encryption_version')->default(0);
            $table->unique(['organization_id', 'reference_hash']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->text('title')->change();
            $table->text('description')->nullable()->change();
            $table->unsignedTinyInteger('title_encryption_version')->default(0);
            $table->unsignedTinyInteger('description_encryption_version')->default(0);
        });

        Schema::table('document_versions', function (Blueprint $table): void {
            $table->text('file_name')->change();
            $table->unsignedTinyInteger('file_name_encryption_version')->default(0);
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->text('title')->change();
            $table->unsignedTinyInteger('title_encryption_version')->default(0);
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->text('encrypted_ip_address')->nullable();
            $table->unsignedTinyInteger('ip_encryption_version')->default(0);
        });
    }

    public function down(): void
    {
        DB::table('legal_cases')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('legal_cases')->where('id', $row->id)->update([
                    'reference' => $this->decrypt($row->reference, $row->reference_encryption_version),
                    'title' => $this->decrypt($row->title, $row->title_encryption_version),
                    'description' => $this->decrypt($row->description, $row->description_encryption_version),
                ]);
            }
        });
        DB::table('documents')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('documents')->where('id', $row->id)->update([
                    'title' => $this->decrypt($row->title, $row->title_encryption_version),
                    'description' => $this->decrypt($row->description, $row->description_encryption_version),
                ]);
            }
        });
        DB::table('document_versions')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('document_versions')->where('id', $row->id)->update([
                    'file_name' => $this->decrypt($row->file_name, $row->file_name_encryption_version),
                ]);
            }
        });
        DB::table('notifications')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('notifications')->where('id', $row->id)->update([
                    'title' => $this->decrypt($row->title, $row->title_encryption_version),
                ]);
            }
        });
        DB::table('audit_events')->whereNotNull('encrypted_ip_address')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('audit_events')->where('id', $row->id)->update([
                    'ip_address' => Crypt::decryptString($row->encrypted_ip_address),
                ]);
            }
        });

        Schema::table('legal_cases', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'reference_hash']);
            $table->dropColumn(['reference_hash', 'reference_encryption_version', 'title_encryption_version', 'description_encryption_version']);
            $table->string('reference', 80)->change();
            $table->string('title', 200)->change();
            $table->text('description')->nullable()->change();
            $table->unique(['organization_id', 'reference']);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['title_encryption_version', 'description_encryption_version']);
            $table->string('title', 200)->change();
            $table->text('description')->nullable()->change();
        });
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn('file_name_encryption_version');
            $table->string('file_name', 255)->change();
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('title_encryption_version');
            $table->string('title', 180)->change();
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn(['encrypted_ip_address', 'ip_encryption_version']);
        });
    }

    private function decrypt(?string $value, ?int $version): ?string
    {
        return $value === null ? null : ((int) $version >= 1 ? Crypt::decryptString($value) : $value);
    }
};

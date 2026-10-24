<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapOrganization extends Command
{
    protected $signature = 'legalcloud:bootstrap {organization} {email} {--name=}';

    protected $description = 'Create the first organization and administrator account';

    public function handle(): int
    {
        $organizationName = trim((string) $this->argument('organization'));
        $email = strtolower(trim((string) $this->argument('email')));
        $adminName = trim((string) ($this->option('name') ?: $this->ask('Administrator name')));
        $password = (string) $this->secret('Initial administrator password (minimum 12 characters)');

        if ($organizationName === '' || $adminName === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->error('Organization, administrator name, valid email, and a 12-character password are required.');

            return self::FAILURE;
        }

        if (User::query()->where('email_hash', User::emailLookupHash($email))->exists()) {
            $this->error('An account with that email already exists.');

            return self::FAILURE;
        }

        $baseSlug = Str::slug($organizationName);
        if ($baseSlug === '') {
            $this->error('The organization name must contain letters or numbers.');

            return self::FAILURE;
        }

        $slug = $baseSlug;
        for ($suffix = 2; Organization::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$baseSlug}-{$suffix}";
        }

        DB::transaction(function () use ($organizationName, $slug, $adminName, $email, $password): void {
            $organization = Organization::query()->create(['name' => $organizationName, 'slug' => $slug]);
            User::query()->create([
                'organization_id' => $organization->id,
                'name' => $adminName,
                'email' => $email,
                'password' => $password,
                'role' => 'administrator',
                'status' => 'active',
            ]);
        });

        $this->info("Organization '{$organizationName}' and administrator '{$email}' created.");
        $this->comment('The password is not displayed. Sign in through POST /api/v1/auth/login.');

        return self::SUCCESS;
    }
}

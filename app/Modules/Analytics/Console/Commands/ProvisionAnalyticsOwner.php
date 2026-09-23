<?php

namespace App\Modules\Analytics\Console\Commands;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\User;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionAnalyticsOwner extends Command
{
    protected $signature = 'analytics:provision-owner
        {email : The owner email address}
        {--name= : The owner display name}
        {--password= : A password for non-interactive provisioning}
        {--workspace=Analytics workspace : The initial workspace name}';

    protected $description = 'Provision the first verified Analytics owner and workspace';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: Str::before($email, '@')));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12) {
            $this->error('Provide a valid email, name, and a password with at least 12 characters.');

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('An Analytics account already exists for that email.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $workspace = Workspace::create(['name' => (string) $this->option('workspace')]);
        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner->value]);

        $this->info("Provisioned {$email} as the owner of {$workspace->name}.");

        return self::SUCCESS;
    }
}

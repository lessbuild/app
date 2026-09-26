<?php

namespace App\Core\Console\Commands;

use App\Core\Models\PlatformUser;
use App\Core\Services\Admin\PlatformAdminAccess;
use Illuminate\Console\Command;
use RuntimeException;

class PlatformAdminCommand extends Command
{
    protected $signature = 'platform:admin
        {email? : Core account email to grant or revoke}
        {--grant : Grant platform administration}
        {--revoke : Revoke platform administration}
        {--allow-last : Allow revoking the last remaining administrator}
        {--import-allowlist : Grant every active Core account listed in PLATFORM_ADMIN_EMAILS}
        {--list : List current platform administrators}';

    protected $description = 'Grant, revoke, list, or import platform administrators (admins also need two-factor or a passkey to use /admin)';

    public function handle(PlatformAdminAccess $access): int
    {
        if ($this->option('list')) {
            $this->table(['Email', 'Granted', 'Second factor'], PlatformUser::query()->where('is_platform_admin', true)->orderBy('email_normalized')->get()
                ->map(fn (PlatformUser $user): array => [$user->email, (string) $user->platform_admin_granted_at, $user->hasSecondFactor() ? 'yes' : 'no'])->all());

            return self::SUCCESS;
        }

        if ($this->option('import-allowlist')) {
            foreach (config('lessbuild.platform_admin_emails', []) as $email) {
                $user = $this->find($email);
                if ($user === null) {
                    $this->warn("No active Core account for {$email}; skipped.");

                    continue;
                }
                $changed = $access->grant($user, null, 'allowlist-import');
                $this->line(($changed ? 'Granted ' : 'Already admin: ').$email);
            }

            return self::SUCCESS;
        }

        $email = (string) $this->argument('email');
        if ($email === '' || $this->option('grant') === $this->option('revoke')) {
            $this->error('Provide an email with exactly one of --grant or --revoke, or use --list / --import-allowlist.');

            return self::INVALID;
        }
        $user = $this->find($email);
        if ($user === null) {
            $this->error("No active Core account for {$email}.");

            return self::FAILURE;
        }

        try {
            $changed = $this->option('grant')
                ? $access->grant($user, null, 'cli')
                : $access->revoke($user, null, 'cli', (bool) $this->option('allow-last'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage().' Use --allow-last to override.');

            return self::FAILURE;
        }

        $this->info($changed ? 'Updated '.$email.'.' : 'No change for '.$email.'.');
        if ($this->option('grant') && ! $user->hasSecondFactor()) {
            $this->warn('This account has no authenticator app or passkey yet; /admin stays closed until one is added.');
        }

        return self::SUCCESS;
    }

    private function find(string $email): ?PlatformUser
    {
        return PlatformUser::query()->where('email_normalized', strtolower(trim($email)))->where('status', 'active')->first();
    }
}

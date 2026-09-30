<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;
use App\Models\MigrationApproval;
use App\Services\Infrastructure\ProvisioningCallbackUrl;

class ArtisanCommandsScript extends BuildProvisioningScript
{
    public const TITLE = 'Run artisan commands';

    public const DESCRIPTION = 'Run the artisan commands';

    public const IDENTIFIER = 'run-artisan-commands';

    /**
     * Render the stage that runs a Laravel release's artisan commands (storage link, caches and migrations, and a
     * Horizon restart) and reports progress.
     *
     * @param  int  $step
     * @param  Build  $build
     * @return string
     */
    public function script(int $step, Build $build): string
    {
        $repository = $build->repository;
        $candidatePath = escapeshellarg($build->deploymentPath('setup'));
        $progress = $this->progress($step, $build);
        $check = $this->migrationCheck($build);

        return <<<SCRIPT

        cd -- {$candidatePath}

        if [ -f artisan ]; then
            php artisan storage:link --force
            php artisan config:cache
            php artisan route:cache
            php artisan view:cache
            php artisan event:cache
        {$check}
            php artisan migrate --force

            if php artisan list --raw | grep -qx 'horizon:terminate'; then
                php artisan horizon:terminate
            fi
        fi

        # Ping
        {$progress}

        SCRIPT;
    }

    /**
     * Build the migration safety check: with it on and the revision not yet approved, list the SQL the pending
     * migrations would run (migrate --pretend runs nothing), and stop the deploy before migrating when any of it drops,
     * truncates or renames, reporting the statements for someone to approve.
     *
     * @param  Build  $build
     * @return string
     */
    private function migrationCheck(Build $build): string
    {
        if (($build->environment_payload['migration_safety'] ?? false) !== true || $build->environment_id === null
            || MigrationApproval::query()->where('environment_id', $build->environment_id)->where('revision', (string) $build->revision)->exists()) {
            return '    # No migration safety check';
        }
        $url = escapeshellarg(ProvisioningCallbackUrl::buildMigrations($build));

        return <<<SCRIPT
                # Migration safety: stop before destructive migrations until someone approves them
                pending_sql="\$(php artisan migrate --pretend --force 2>&1 || true)"
                destructive="\$(printf '%s\\n' "\$pending_sql" | grep -Ei 'drop (table|column|index|constraint|foreign)|alter table .* drop|truncate|rename (table|column|to)|alter table .* rename' | head -n 40 || true)"
                if [ -n "\$destructive" ]; then
                    echo "Destructive migrations found:"
                    echo "\$destructive"
                    curl --silent --show-error --max-time 30 --data-urlencode "statements=\$destructive" {$url} >/dev/null || true
                    DEPLOYMENT_FAILURE_MESSAGE="Destructive migrations need approval before they run."
                    false
                fi
        SCRIPT;
    }
}

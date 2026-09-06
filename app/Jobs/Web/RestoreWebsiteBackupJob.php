<?php

namespace App\Jobs\Web;

use App\Models\BackupRestore;
use App\Models\WebsiteBackup;
use App\Services\ResticRepository;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RestoreWebsiteBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $restoreId) {}

    public function handle(Runner $runner, ResticRepository $repositories): void
    {
        $restore = BackupRestore::query()->with(['backup.website.server', 'backup.destination'])->find($this->restoreId);
        if (! $restore || $restore->status !== BackupRestore::STATUS_QUEUED) {
            return;
        }
        $backup = $restore->backup;
        $website = $backup->website;
        if ($backup->status !== WebsiteBackup::STATUS_SUCCEEDED || ! preg_match('/\A[a-f0-9]{8,64}\z/D', (string) $backup->snapshot_id)) {
            throw new RuntimeException('The backup does not contain a restorable snapshot.');
        }
        if ($website->hasActiveDeployment()) {
            throw new RuntimeException('A deployment started before the restore could run.');
        }
        if (! $website->server->mysql_root_password) {
            throw new RuntimeException('The managed server does not have a stored MySQL root credential.');
        }
        $restore->update(['status' => BackupRestore::STATUS_RUNNING, 'started_at' => now(), 'error' => null]);
        $restic = $repositories->shell($backup->destination, $website);
        $stage = escapeshellarg("/tmp/buildpusher-restore-{$restore->id}");
        $root = escapeshellarg("/var/www/{$website->deployment_slug}");
        $password = escapeshellarg($website->server->mysql_root_password);
        $database = escapeshellarg($website->databaseIdentifier());
        $snapshot = escapeshellarg($backup->snapshot_id);
        $healthUrl = escapeshellarg("http://{$website->url}{$website->health_check_path}");
        $healthCheck = $website->health_check_enabled
            ? "curl --fail --silent --show-error --location --connect-timeout 5 --max-time 20 --retry 3 --output /dev/null {$healthUrl}"
            : 'true';
        $command = <<<BASH
        set -Eeuo pipefail
        STAGE={$stage}
        APP_ROOT={$root}
        DATABASE={$database}
        SAFETY_STORAGE="\$STAGE/safety-storage"
        rollback_restore() {
            code=\$?
            trap - ERR
            if [ -f "\$STAGE/safety.sql" ]; then MYSQL_PWD={$password} mysql < "\$STAGE/safety.sql" || true; fi
            if [ -d "\$SAFETY_STORAGE" ]; then rm -rf -- "\$APP_ROOT/shared/storage"; mv -- "\$SAFETY_STORAGE" "\$APP_ROOT/shared/storage"; fi
            if [ -f "\$STAGE/safety.env" ]; then cp -- "\$STAGE/safety.env" "\$APP_ROOT/.env"; fi
            if [ -f "\$APP_ROOT/current/artisan" ]; then cd -- "\$APP_ROOT/current" && php artisan up || true; fi
            rm -rf -- "\$STAGE"
            exit "\$code"
        }
        trap rollback_restore ERR
        install -d -m 700 -- "\$STAGE/restore"
        if ! command -v restic >/dev/null 2>&1; then apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic; fi
        {$restic['environment']} restic restore {$snapshot} --target "\$STAGE/restore"
        test -s "\$STAGE/restore/database.sql"
        test -d "\$STAGE/restore/storage"
        if [ -f "\$APP_ROOT/current/artisan" ]; then cd -- "\$APP_ROOT/current" && php artisan down --retry=30; fi
        MYSQL_PWD={$password} mysqldump --single-transaction --routines --triggers --events --databases "\$DATABASE" > "\$STAGE/safety.sql"
        cp -- "\$APP_ROOT/.env" "\$STAGE/safety.env"
        mv -- "\$APP_ROOT/shared/storage" "\$SAFETY_STORAGE"
        install -d -o www-data -g www-data -m 775 -- "\$APP_ROOT/shared/storage"
        rsync -a --delete "\$STAGE/restore/storage/" "\$APP_ROOT/shared/storage/"
        cp -- "\$STAGE/restore/.env" "\$APP_ROOT/.env"
        MYSQL_PWD={$password} mysql < "\$STAGE/restore/database.sql"
        chown -R www-data:www-data "\$APP_ROOT/shared/storage"
        chmod 640 "\$APP_ROOT/.env"
        if [ -f "\$APP_ROOT/current/artisan" ]; then cd -- "\$APP_ROOT/current" && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan up; fi
        {$healthCheck}
        trap - ERR
        rm -rf -- "\$STAGE"
        BASH;
        $result = $runner->server($website->server)->create()->execute($command);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput() ?: $result->getOutput()) ?: 'Remote restore failed and its safety rollback was attempted.');
        }
        $restore->update(['status' => BackupRestore::STATUS_SUCCEEDED, 'completed_at' => now()]);
    }

    public function failed(\Throwable $exception): void
    {
        BackupRestore::query()->whereKey($this->restoreId)->update([
            'status' => BackupRestore::STATUS_FAILED,
            'completed_at' => now(),
            'error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}

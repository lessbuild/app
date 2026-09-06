<?php

namespace App\Jobs\Web;

use App\Models\WebsiteBackup;
use App\Services\ResticRepository;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class CreateWebsiteBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(public int $backupId) {}

    public function handle(Runner $runner, ResticRepository $repositories): void
    {
        $backup = WebsiteBackup::query()->with(['website.server', 'destination', 'schedule'])->find($this->backupId);
        if (! $backup || $backup->status !== WebsiteBackup::STATUS_QUEUED) {
            return;
        }
        $backup->update(['status' => WebsiteBackup::STATUS_RUNNING, 'started_at' => now(), 'error' => null]);
        $website = $backup->website;
        $server = $website->server;
        if (! $server->mysql_root_password) {
            throw new RuntimeException('The managed server does not have a stored MySQL root credential.');
        }
        $restic = $repositories->shell($backup->destination, $website);
        $stage = "/tmp/buildpusher-backup-{$backup->id}";
        $root = "/var/www/{$website->deployment_slug}";
        $database = $website->databaseIdentifier();
        $retention = max(1, min(365, $backup->schedule?->retention_count ?? 14));
        $stageArgument = escapeshellarg($stage);
        $rootArgument = escapeshellarg($root);
        $passwordArgument = escapeshellarg($server->mysql_root_password);
        $databaseArgument = escapeshellarg($database);
        $tagArgument = escapeshellarg('website:'.$website->id);
        $command = <<<BASH
        set -Eeuo pipefail
        STAGE={$stageArgument}
        APP_ROOT={$rootArgument}
        cleanup() { rm -rf -- "\$STAGE"; }
        trap cleanup EXIT
        if ! command -v restic >/dev/null 2>&1; then
            apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic
        fi
        install -d -m 700 -- "\$STAGE/storage"
        MYSQL_PWD={$passwordArgument} mysqldump --single-transaction --routines --triggers --events --databases {$databaseArgument} > "\$STAGE/database.sql"
        cp -- "\$APP_ROOT/.env" "\$STAGE/.env"
        if [ -d "\$APP_ROOT/shared/storage" ]; then rsync -a --delete "\$APP_ROOT/shared/storage/" "\$STAGE/storage/"; fi
        cd -- "\$STAGE"
        if ! {$restic['environment']} restic snapshots --json >/dev/null 2>&1; then {$restic['environment']} restic init; fi
        {$restic['environment']} restic backup --json --tag {$tagArgument} database.sql .env storage
        {$restic['environment']} restic forget --keep-last {$retention} --tag {$tagArgument} --prune
        BASH;
        $result = $runner->server($server)->create()->execute($command);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput() ?: $result->getOutput()) ?: 'Remote backup failed.');
        }
        $output = $result->getOutput();
        if (! preg_match('/"snapshot_id"\s*:\s*"([a-f0-9]{8,64})"/i', $output, $snapshot)) {
            throw new RuntimeException('Restic did not return a snapshot identifier.');
        }
        preg_match('/"total_bytes_processed"\s*:\s*(\d+)/', $output, $bytes);
        $backup->update([
            'status' => WebsiteBackup::STATUS_SUCCEEDED,
            'https_verified_at' => parse_url($backup->destination->endpoint, PHP_URL_SCHEME) === 'https' ? now() : null,
            'snapshot_id' => strtolower($snapshot[1]),
            'size_bytes' => isset($bytes[1]) ? (int) $bytes[1] : null,
            'completed_at' => now(),
        ]);
        $backup->destination->update(['last_verified_at' => now(), 'last_error' => null]);
    }

    public function failed(\Throwable $exception): void
    {
        WebsiteBackup::query()->whereKey($this->backupId)->update([
            'status' => WebsiteBackup::STATUS_FAILED,
            'completed_at' => now(),
            'error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}

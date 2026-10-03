<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Models\ServerDiskScan;
use App\Services\Infrastructure\DiskCleanup;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/** Clears one kind of clearable file on a server when asked, then measures what's clearable. */
final class ScanServerDisk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds a scan and clean may take.
     *
     * @var int
     */
    public int $timeout = 600;

    /**
     * Create a new ScanServerDisk instance.
     *
     * @param  int  $serverId  The server.
     * @param  string|null  $clean  The category to clear first, if any.
     */
    public function __construct(public readonly int $serverId, public readonly ?string $clean = null) {}

    /**
     * Clear the category (if asked), then scan and record the findings.
     *
     * @param  ServerShell  $shell
     * @param  DiskCleanup  $cleanup
     * @return void
     */
    public function handle(ServerShell $shell, DiskCleanup $cleanup): void
    {
        $server = Server::query()->find($this->serverId);
        $scan = ServerDiskScan::query()->where('server_id', $this->serverId)->first();
        if ($server === null || $scan === null || $server->provisioning_status !== Server::STATUS_ACTIVE) {
            return;
        }
        $scan->forceFill(['status' => 'running'])->save();
        if ($this->clean !== null) {
            $cleaned = $shell->run($server, $cleanup->clean($server, $this->clean));
            if (! $cleaned->successful()) {
                $scan->forceFill(['status' => 'failed', 'error' => Str::limit(trim($cleaned->errorOutput ?: $cleaned->output), 480)])->save();

                return;
            }
        }
        $result = $shell->run($server, $cleanup->scan($server));
        if (! $result->successful()) {
            $scan->forceFill(['status' => 'failed', 'error' => Str::limit(trim($result->errorOutput ?: $result->output), 480)])->save();

            return;
        }
        $findings = [];
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (preg_match('/\A(releases|logs|caches|docker|tmp|disk) (\d+) (\d+)\z/', trim($line), $match) === 1) {
                $findings[$match[1]] = ['bytes' => (int) $match[2], 'items' => (int) $match[3]];
            }
        }
        $scan->forceFill(['status' => 'ready', 'findings' => $findings, 'error' => null, 'scanned_at' => now(), 'last_cleaned' => $this->clean ?? $scan->last_cleaned])->save();
    }
}

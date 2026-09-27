<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\DatabaseSnapshot;
use App\Services\Infrastructure\DatabaseCommands;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Reads a website database's size, connections and tables into its snapshot, and drops snapshots older than 30 days. */
final class InspectDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt; someone can run another inspection.
     */
    public int $tries = 1;

    /**
     * Listing tables of a large database can take a few minutes.
     */
    public int $timeout = 300;

    /**
     * Reads a website's database size, tables and connection count.
     *
     * @param  int  $snapshotId  The queued snapshot to fill in.
     */
    public function __construct(public readonly int $snapshotId) {}

    /**
     * Claims the snapshot, runs the inspection on the server, stores what it reports, and removes the website's
     * snapshots older than 30 days.
     */
    public function handle(ServerShell $shell, DatabaseCommands $commands): void
    {
        if (DatabaseSnapshot::query()->whereKey($this->snapshotId)->where('status', 'queued')->update(['status' => 'running']) === 0) {
            return;
        }
        $snapshot = DatabaseSnapshot::query()->with('website.server')->findOrFail($this->snapshotId);
        $server = $snapshot->website->server;
        $result = $server === null ? null : $shell->run($server, $commands->inspect($snapshot->website));
        if ($result === null || ! $result->successful()) {
            $this->failed(new RuntimeException('The database couldn’t be inspected.'));

            return;
        }
        $values = ['tables' => []];
        foreach (preg_split('/\R/', trim($result->output)) ?: [] as $line) {
            if (str_starts_with($line, 'table=')) {
                $values['tables'][] = substr($line, 6);
            } elseif (preg_match('/\A(size_bytes|active_connections)=(\d+)\z/', $line, $match) === 1) {
                $values[$match[1]] = (int) $match[2];
            }
        }
        $snapshot->forceFill([...$values, 'status' => 'ready', 'error' => null, 'collected_at' => CarbonImmutable::now()])->save();
        DatabaseSnapshot::query()->where('website_id', $snapshot->website_id)->where('created_at', '<', now()->subDays(30))->delete();
    }

    /**
     * Marks the snapshot failed.
     */
    public function failed(Throwable $exception): void
    {
        DatabaseSnapshot::query()->whereKey($this->snapshotId)->first()?->forceFill(['status' => 'failed', 'error' => $exception->getMessage(), 'collected_at' => now()])->save();
    }
}

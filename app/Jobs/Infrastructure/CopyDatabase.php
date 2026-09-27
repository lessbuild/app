<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\DatabaseClone;
use App\Services\Infrastructure\DatabaseCommands;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Copies one website's database over another's on the same server. Not retried: a person decides whether to run it again. */
final class CopyDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One attempt: a half-finished copy overwrites the target, so it isn't retried blindly.
     */
    public int $tries = 1;

    /**
     * Copying a large database can take up to an hour.
     */
    public int $timeout = 3600;

    /**
     * Copies one website's database over another's on the same server.
     *
     * @param  int  $cloneId  The queued copy.
     */
    public function __construct(public readonly int $cloneId) {}

    /**
     * Claims the copy, checks both websites are still on the same server, and runs it.
     */
    public function handle(ServerShell $shell, DatabaseCommands $commands): void
    {
        if (DatabaseClone::query()->whereKey($this->cloneId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]) === 0) {
            return;
        }
        $clone = DatabaseClone::query()->with(['source.server', 'target.server'])->findOrFail($this->cloneId);
        $server = $clone->source->server;
        if ($server === null || $clone->source->server_id !== $clone->target->server_id || $clone->source->trashed() || $clone->target->trashed()) {
            $this->failed(new RuntimeException('Both websites must still be on the same server.'));

            return;
        }
        $result = $shell->run($server, $commands->copy($clone->source, $clone->target));
        if (! $result->successful()) {
            $this->failed(new RuntimeException('The copy failed: '.str(trim($result->errorOutput ?: $result->output))->limit(500)));

            return;
        }
        $clone->forceFill(['status' => 'succeeded', 'error' => null, 'finished_at' => now()])->save();
    }

    /**
     * Marks the copy failed with the reason.
     */
    public function failed(Throwable $exception): void
    {
        DatabaseClone::query()->whereKey($this->cloneId)->first()?->forceFill(['status' => 'failed', 'error' => str($exception->getMessage())->limit(1000)->toString(), 'finished_at' => now()])->save();
    }
}

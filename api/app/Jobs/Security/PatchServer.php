<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Data\Infrastructure\ShellResult;
use App\Models\Server;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\ServerSnapshots;
use App\Services\Security\HardeningScripts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class PatchServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One try: the next window tries again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Up to thirty minutes.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * Create a new PatchServer instance.
     *
     * Installs a server's security updates.
     *
     * @param  int  $serverId  The server.
     * @param  bool  $reboot  Whether to reboot afterwards if the updates need it.
     */
    public function __construct(public readonly int $serverId, public readonly bool $reboot) {}

    /**
     * Install the updates and record when; reboot a minute later if asked and needed.
     *
     * @param  ServerShell  $shell
     * @param  HardeningScripts  $scripts
     * @param  ServerSnapshots  $snapshots
     * @return void
     */
    public function handle(ServerShell $shell, HardeningScripts $scripts, ServerSnapshots $snapshots): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null || $server->provisioning_status !== Server::STATUS_ACTIVE) {
            return;
        }
        $snapshots->before($server, 'Before installing updates');
        $result = $this->runUpdates(fn () => $shell->run($server, $scripts->updates($this->reboot), timeout: HardeningScripts::UPDATE_SECONDS));
        if (! $result->successful()) {
            throw new RuntimeException(str(trim($result->errorOutput ?: $result->output))->limit(500)->toString() ?: 'Updating failed.');
        }
        $server->forceFill(['last_patched_at' => now(), 'last_patch_error' => null])->save();
    }

    /**
     * Record why updating failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Server::query()->whereKey($this->serverId)->update(['last_patch_error' => str($exception->getMessage())->limit(500)->toString(), 'last_patched_at' => now()]);
    }

    /**
     * Run the script, turning a time-out into a reason people can act on (the server finishes any half-done install
     * next time).
     *
     * @param  callable(): ShellResult  $run
     * @return ShellResult
     */
    private function runUpdates(callable $run): ShellResult
    {
        try {
            return $run();
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException(__('It took longer than :minutes minutes on the server and was stopped. Try again: anything left half-installed is finished first.', ['minutes' => (int) round($exception->getExceededTimeout() / 60)]), 0, $exception);
        }
    }
}

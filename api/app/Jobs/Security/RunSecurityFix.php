<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\QueueSecurityScan;
use App\Data\Infrastructure\ShellResult;
use App\Models\SecurityFinding;
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

final class RunSecurityFix implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One try: fixes change servers, so they aren't retried blindly.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Up to fifteen minutes (updates can be slow).
     *
     * @var int
     */
    public int $timeout = 900;

    /**
     * Create a new RunSecurityFix instance.
     *
     * Applies one fix to one server.
     *
     * @param  int  $findingId  The finding it fixes.
     * @param  int  $serverId  The server.
     * @param  string  $action  one of HardeningScripts::ACTIONS' keys
     */
    public function __construct(public readonly int $findingId, public readonly int $serverId, public readonly string $action) {}

    /**
     * Run the fix, then audit the project's servers again so the finding clears if it worked.
     *
     * @param  ServerShell  $shell
     * @param  HardeningScripts  $scripts
     * @param  QueueSecurityScan  $scan
     * @param  ServerSnapshots  $snapshots
     * @return void
     */
    public function handle(ServerShell $shell, HardeningScripts $scripts, QueueSecurityScan $scan, ServerSnapshots $snapshots): void
    {
        $finding = SecurityFinding::query()->with('project')->find($this->findingId);
        $server = Server::query()->find($this->serverId);
        if ($finding === null || $server === null) {
            return;
        }
        $snapshots->before($server, 'Before a security fix: '.$this->action);
        $result = $this->runUpdates(fn () => $shell->run($server, $scripts->script($this->action, $server), timeout: HardeningScripts::timeout($this->action)));
        if (! $result->successful()) {
            throw new RuntimeException(str(trim($result->errorOutput ?: $result->output))->limit(500)->toString() ?: 'The fix failed.');
        }
        $finding->forceFill(['data' => [...($finding->data ?? []), 'fix_status' => 'done', 'fix_error' => null]])->save();
        if ($this->action !== 'reboot') {
            $scan->handle($finding->project, 'servers');
        }
    }

    /**
     * Record why the fix failed on the finding.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $finding = SecurityFinding::query()->find($this->findingId);
        $finding?->forceFill(['data' => [...($finding->data ?? []), 'fix_status' => 'failed', 'fix_error' => str($exception->getMessage())->limit(500)->toString()]])->save();
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

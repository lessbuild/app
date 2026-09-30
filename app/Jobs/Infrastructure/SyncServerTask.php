<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\ServerCronJob;
use App\Models\ServerFirewallRule;
use App\Models\ServerProcess;
use App\Services\Infrastructure\ServerShell;
use App\Services\Infrastructure\ServerTaskScripts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

final class SyncServerTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The server can be busy (apt, a deploy), so a change gets three tries.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 30;

    /**
     * How long a change may take (installing Supervisor the first time takes a while).
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new SyncServerTask instance.
     *
     * Puts a cron job, process or firewall rule on its server, takes it off, or restarts a process.
     *
     * @param  class-string<ServerCronJob|ServerProcess|ServerFirewallRule>  $type  Which kind of task.
     * @param  int  $taskId  The task.
     * @param  string  $operation  `apply`, `remove` or `restart`.
     */
    public function __construct(public readonly string $type, public readonly int $taskId, public readonly string $operation) {}

    /**
     * Run the change if the task is still waiting for it, then mark it active, or delete it once removed.
     *
     * @param  ServerShell  $shell
     * @param  ServerTaskScripts  $scripts
     * @return void
     */
    public function handle(ServerShell $shell, ServerTaskScripts $scripts): void
    {
        $task = $this->task();
        $expected = ['apply' => 'pending', 'remove' => 'removing', 'restart' => 'active'][$this->operation] ?? null;
        if ($task === null || $task->getAttribute('status') !== $expected) {
            return;
        }
        $command = match ($this->operation) {
            'remove' => $scripts->remove($task),
            'restart' => $task instanceof ServerProcess ? $scripts->restart($task) : throw new RuntimeException('Only processes restart.'),
            default => $scripts->apply($task),
        };
        $result = $shell->run($task->server, $command);
        if (! $result->successful()) {
            throw new RuntimeException('The server refused the change: '.str(trim($result->errorOutput ?: $result->output))->limit(500));
        }
        if ($this->operation === 'remove') {
            $task->delete();

            return;
        }
        $task->forceFill(['status' => 'active', 'error' => null, 'applied_at' => now()])->save();
    }

    /**
     * Mark the task failed with the server's error.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->task()?->forceFill(['status' => 'failed', 'error' => str($exception->getMessage())->limit(1000)->toString()])->save();
    }

    /**
     * Load the task with its server.
     *
     * @return ServerCronJob|ServerProcess|ServerFirewallRule|null
     */
    private function task(): ServerCronJob|ServerProcess|ServerFirewallRule|null
    {
        /** @var ServerCronJob|ServerProcess|ServerFirewallRule|null $task */
        $task = in_array($this->type, [ServerCronJob::class, ServerProcess::class, ServerFirewallRule::class], true)
            ? $this->type::query()->with('server')->find($this->taskId)
            : null;

        return $task;
    }
}

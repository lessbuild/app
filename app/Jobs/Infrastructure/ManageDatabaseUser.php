<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\DatabaseUser;
use App\Services\Infrastructure\DatabaseCommands;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Creates (or updates) a database user on the server, or drops it and deletes the record. Retried a few times on SSH trouble. */
final class ManageDatabaseUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(public readonly int $userId, public readonly string $operation) {}

    public function handle(ServerShell $shell, DatabaseCommands $commands): void
    {
        $user = DatabaseUser::query()->with('website.server')->find($this->userId);
        if ($user === null || $user->status !== ($this->operation === 'remove' ? 'removing' : 'pending')) {
            return;
        }
        $server = $user->website->server ?? throw new RuntimeException('The website has no server.');
        $result = $shell->run($server, $this->operation === 'remove' ? $commands->removeUser($user) : $commands->applyUser($user));
        if (! $result->successful()) {
            throw new RuntimeException('MySQL refused the change: '.str(trim($result->errorOutput ?: $result->output))->limit(500));
        }
        if ($this->operation === 'remove') {
            $user->delete();

            return;
        }
        $user->forceFill(['status' => 'active', 'error' => null, 'applied_at' => now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        DatabaseUser::query()->whereKey($this->userId)->first()?->forceFill(['status' => 'failed', 'error' => str($exception->getMessage())->limit(1000)->toString()])->save();
    }
}

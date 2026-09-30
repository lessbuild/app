<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Actions\Infrastructure\RequestDatabaseInspection;
use App\Models\User;
use App\Models\Website;
use App\Services\Infrastructure\DatabaseCommands;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class EnableSlowLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new EnableSlowLog instance.
     *
     * @param  int  $websiteId  The website whose database server it is.
     * @param  string  $userId  Who asked, for the inspection afterwards.
     */
    public function __construct(public readonly int $websiteId, public readonly string $userId) {}

    /**
     * Turn the slow query log on, then queue a fresh inspection.
     *
     * @param  ServerShell  $shell
     * @param  DatabaseCommands  $commands
     * @param  RequestDatabaseInspection  $inspect
     * @return void
     */
    public function handle(ServerShell $shell, DatabaseCommands $commands, RequestDatabaseInspection $inspect): void
    {
        $website = Website::query()->with('server')->find($this->websiteId);
        $user = User::query()->find($this->userId);
        if ($website === null || $website->server === null || $user === null) {
            return;
        }
        $result = $shell->run($website->server, $commands->enableSlowLog($website));
        if (! $result->successful()) {
            throw new RuntimeException('Couldn’t turn on the slow query log: '.trim($result->errorOutput ?: $result->output));
        }
        $inspect->handle($website, $user);
    }
}

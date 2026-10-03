<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Actions\Deploy\SyncSecrets;
use App\Models\SecretSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class RunSecretSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new RunSecretSync instance.
     *
     * @param  int  $syncId  The sync to run.
     */
    public function __construct(public readonly int $syncId) {}

    /**
     * Sync the password manager's secrets into the environment.
     *
     * @param  SyncSecrets  $sync
     * @return void
     */
    public function handle(SyncSecrets $sync): void
    {
        $secretSync = SecretSync::query()->find($this->syncId);
        if ($secretSync !== null) {
            $sync->handle($secretSync);
        }
    }
}

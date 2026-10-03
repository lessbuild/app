<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Models\Server;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

final class SyncSshAccess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Two tries.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 30;

    /**
     * Create a new SyncSshAccess instance.
     *
     * Brings one person's keys on one server in line with their access.
     *
     * @param  int  $serverId  The server.
     * @param  string  $userId  The person.
     */
    public function __construct(public readonly int $serverId, public readonly string $userId) {}

    /**
     * Replace the person's keys in the deploy user's authorized_keys: their current keys while the grant is pending or
     * active, none when it's being removed (then the grant is deleted). Each key line ends with a marker naming them,
     * so their keys can be told apart and removed without touching anyone else's.
     *
     * @param  ServerShell  $shell
     * @return void
     */
    public function handle(ServerShell $shell): void
    {
        $server = Server::query()->find($this->serverId);
        $grant = ServerSshGrant::query()->where('server_id', $this->serverId)->where('user_id', $this->userId)->first();
        if ($server === null || $grant === null) {
            return;
        }
        $removing = $grant->status === 'removing';
        $marker = 'buildpusher-user:'.$this->userId;
        $keys = $removing ? [] : UserSshKey::query()->where('user_id', $this->userId)->pluck('public_key')->map(fn (string $key): string => "{$key} {$marker}")->all();
        $home = escapeshellarg('/home/'.$server->name.'/.ssh');
        $file = escapeshellarg('/home/'.$server->name.'/.ssh/authorized_keys');
        $owner = escapeshellarg($server->name);
        $lines = escapeshellarg(implode("\n", $keys));
        $pattern = escapeshellarg(' '.$marker.'$');
        $result = $shell->run($server, <<<BASH
        install -d -m 700 -o {$owner} -g {$owner} {$home}
        touch {$file}
        grep -v -E {$pattern} {$file} > {$file}.next || true
        [ -n {$lines} ] && printf '%s\\n' {$lines} >> {$file}.next
        chown {$owner}:{$owner} {$file}.next && chmod 600 {$file}.next && mv {$file}.next {$file}
        BASH);
        if (! $result->successful()) {
            throw new RuntimeException(str(trim($result->errorOutput ?: $result->output))->limit(300)->toString() ?: 'Updating SSH keys failed.');
        }
        $removing ? $grant->delete() : $grant->forceFill(['status' => 'active', 'error' => null, 'applied_at' => now()])->save();
    }

    /**
     * Record why the keys couldn't be updated.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        ServerSshGrant::query()->where('server_id', $this->serverId)->where('user_id', $this->userId)
            ->update(['status' => 'failed', 'error' => str($exception->getMessage())->limit(500)->toString()]);
    }

    /**
     * Queue a sync of every server someone has access to, such as after they add or remove a key.
     *
     * @param  User  $user
     * @return void
     */
    public static function everywhere(User $user): void
    {
        ServerSshGrant::query()->where('user_id', $user->id)->pluck('server_id')->each(fn (int $serverId) => self::dispatch($serverId, $user->id)->afterCommit());
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\ServerProcess;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetUpReverb
{
    /**
     * The first port Reverb servers use on a server; each website's gets the next free one.
     *
     * @var int
     */
    public const FIRST_PORT = 8080;

    /**
     * Create a new SetUpReverb instance.
     *
     * @param  SaveServerTask  $processes  Adds the Reverb process.
     * @param  UpdateWebsiteCaddyDirectives  $directives  Routes WebSocket requests to it.
     */
    public function __construct(private readonly SaveServerTask $processes, private readonly UpdateWebsiteCaddyDirectives $directives) {}

    /**
     * Run Laravel Reverb for a website: a process (kept alive by Supervisor) on its own local port, and Caddy
     * directives that send /app and /apps (Reverb's WebSocket and HTTP API paths) to it. The site's .env then needs
     * REVERB_SERVER_PORT set to the returned port.
     *
     * @param  User  $actor
     * @param  Website  $website
     * @return int the port Reverb listens on
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Website $website): int
    {
        Gate::forUser($actor)->authorize('update', $website);
        $server = $website->server ?? throw ValidationException::withMessages(['reverb' => __('The website has no server yet.')]);
        $name = mb_substr('Reverb: '.$website->name, 0, 60);
        if (ServerProcess::query()->where('server_id', $server->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['reverb' => __('Reverb is already set up for this website.')]);
        }
        $taken = ServerProcess::query()->where('server_id', $server->id)->where('name', 'like', 'Reverb: %')->count();
        $port = self::FIRST_PORT + $taken;
        $this->processes->handle($actor, $server, ServerProcess::class, [
            'name' => $name,
            'command' => "php artisan reverb:start --host=127.0.0.1 --port={$port}",
            'directory' => $website->deploymentPath('current'),
            'user' => $server->name,
            'processes' => 1,
            'stop_wait_seconds' => 10,
        ]);
        $existing = trim((string) $website->caddy_directives);
        $this->directives->handle($actor, $website, trim($existing."\n# Laravel Reverb\n@reverb path /app/* /apps/*\nreverse_proxy @reverb 127.0.0.1:{$port}"));

        return $port;
    }
}

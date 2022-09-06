<?php

namespace App\Actions\Droplet;

use App\Models\Server;
use App\Scripts\Cache\InstallMemcachedScript;
use App\Scripts\Cache\InstallRedisScript;
use App\Scripts\Database\InstallMysqlScript;
use App\Scripts\Languages\InstallNodeScript;
use App\Scripts\Languages\InstallPHPScript;
use App\Scripts\Server\BaseScript;
use App\Scripts\Server\ConfigureServerScript;
use App\Scripts\Server\ConfigureSwapScript;
use App\Scripts\Server\EndScript;
use App\Scripts\Server\InstallComposerScript;
use App\Scripts\Server\RecipesScript;
use App\Scripts\Server\UpdateDependenciesScript;
use App\Scripts\Web\InstallCaddyScript;
use App\Services\DigitalOcean;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Ssh\Ssh;

class CreateDropletAction
{
    // Scripts to run
    public array $commands = [
        BaseScript::class,
        UpdateDependenciesScript::class,
        ConfigureSwapScript::class,
        ConfigureServerScript::class,
        InstallComposerScript::class,
        InstallPHPScript::class,
        InstallNodeScript::class,
        InstallCaddyScript::class,
        InstallMysqlScript::class,
        InstallRedisScript::class,
        InstallMemcachedScript::class,
        RecipesScript::class,
        EndScript::class
    ];
    /**
     * @var \App\Models\Server
     */
    private Server $server;

    /**
     * @var \App\Services\DigitalOcean
     */
    private DigitalOcean $serverProvider;

    /**
     * @param \App\Models\Server $server
     * @param \App\Services\DigitalOcean $serverProvider
     */
    public function __construct(Server $server, DigitalOcean $serverProvider)
    {
        $this->server = $server;
        $this->serverProvider = $serverProvider;
    }

    /**
     * @param array $data
     * @return array
     *
     * @throws \Exception
     */
    public function handle(array $data): array
    {
        $script = null;
        foreach ($this->commands as $key => $command) {
            $script .= (new $command)->script($key, $this->server)."\n";
        }

        return $this->serverProvider->createDroplet(array_merge($data, [
            'user_data' => $script
        ]));
    }

    /**
     * Old way to create init script (use in future for other servers)
     *
     * @param string $script
     * @return string
     */
    private function createScript(string $script): string
    {
        $identifier = Str::random();
        $path = "setup-server-$identifier.sh";

        Storage::put($path, $script);

        return config('app.url') . Storage::url($path);
    }
}

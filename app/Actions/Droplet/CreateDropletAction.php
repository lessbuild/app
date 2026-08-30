<?php

namespace App\Actions\Droplet;

use App\Models\Server;
use App\Services\DigitalOcean;
use App\Services\ServerProvisioningPlan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateDropletAction
{
    private Server $server;

    private DigitalOcean $serverProvider;

    private ServerProvisioningPlan $plan;

    public function __construct(Server $server, DigitalOcean $serverProvider, ?ServerProvisioningPlan $plan = null)
    {
        $this->server = $server;
        $this->serverProvider = $serverProvider;
        $this->plan = $plan ?? app(ServerProvisioningPlan::class);
    }

    /**
     * @throws \Exception
     */
    public function handle(array $data): array
    {
        $script = null;
        foreach ($this->plan->scripts($this->server) as $key => $command) {
            $script .= (new $command)->script($key, $this->server)."\n";
        }

        return $this->serverProvider->createDroplet(array_merge($data, [
            'user_data' => $script,
        ]));
    }

    /**
     * Old way to create init script (use in future for other servers)
     */
    private function createScript(string $script): string
    {
        $identifier = Str::random();
        $path = "setup-server-$identifier.sh";

        Storage::put($path, $script);

        return config('app.url').Storage::url($path);
    }
}

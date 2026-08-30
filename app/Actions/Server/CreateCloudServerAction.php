<?php

namespace App\Actions\Server;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Models\Server;
use App\Services\ServerProvisioningPlan;

class CreateCloudServerAction
{
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

    public function handle(Server $server, ServerProvider $provider, array $data): CloudServerData
    {
        $script = '';
        foreach ($this->plan->scripts($server) as $step => $command) {
            $script .= (new $command)->script($step, $server)."\n";
        }

        return $provider->createServer(array_merge($data, [
            'user_data' => $script,
        ]));
    }
}

<?php

namespace App\Actions\Server;

use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Models\Server;
use App\Services\ProvisioningScriptRenderer;
use App\Services\ServerProvisioningPlan;

class CreateCloudServerAction
{
    private ProvisioningScriptRenderer $renderer;

    public function __construct(
        private readonly ServerProvisioningPlan $plan,
        private readonly PrepareServerProvisioningAction $prepare,
        ?ProvisioningScriptRenderer $renderer = null,
    ) {
        $this->renderer = $renderer ?? app(ProvisioningScriptRenderer::class);
    }

    public function handle(Server $server, ServerProvider $provider, array $data): CloudServerData
    {
        $this->prepare->handle($server);

        $script = $this->renderer->server($server, $this->plan->scripts($server));

        return $provider->createServer(array_merge($data, [
            'user_data' => $script,
        ]));
    }
}

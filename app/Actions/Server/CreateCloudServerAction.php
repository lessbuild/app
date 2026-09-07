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

    /**
     * Prepare server credentials and render the provisioning plan before provider creation.
     *
     * @param  ServerProvisioningPlan  $plan  Ordered provisioning or deployment plan defining the steps to render.
     * @param  PrepareServerProvisioningAction  $prepare  Action generating the credentials required by the server provisioning plan.
     * @param  ProvisioningScriptRenderer|null  $renderer  Optional provisioning script renderer; null uses the application default.
     */
    public function __construct(
        private readonly ServerProvisioningPlan $plan,
        private readonly PrepareServerProvisioningAction $prepare,
        ?ProvisioningScriptRenderer $renderer = null,
    ) {
        $this->renderer = $renderer ?? app(ProvisioningScriptRenderer::class);
    }

    /**
     * Prepare provisioning credentials, replace user_data with the rendered startup script, and request the cloud server from its provider.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @param  ServerProvider  $provider  Authenticated provider responsible for creating this server.
     * @param  array<string, mixed>  $data  Provider creation attributes; user_data is replaced with the generated provisioning script.
     * @return CloudServerData Provider response identifying the newly requested cloud server.
     */
    public function handle(Server $server, ServerProvider $provider, array $data): CloudServerData
    {
        $this->prepare->handle($server);

        $script = $this->renderer->server($server, $this->plan->scripts($server));

        return $provider->createServer(array_merge($data, [
            'user_data' => $script,
        ]));
    }
}

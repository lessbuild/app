<?php

namespace App\Actions\Server;

use App\Abstracts\Publishable;
use App\Models\Server;
use App\Services\ProvisioningScriptRenderer;
use App\Services\Runner;
use App\Services\ServerProvisioningPlan;
use RuntimeException;

class RetryRemoteServerProvisioningAction extends Publishable
{
    private ProvisioningScriptRenderer $renderer;

    private ServerProvisioningPlan $plan;

    /**
     * Bind the server and remaining provisioning plan to remote script execution.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @param  Runner|null  $runner  Optional SSH runner; null creates the default runner for this operation.
     * @param  ProvisioningScriptRenderer|null  $renderer  Optional provisioning script renderer; null uses the application default.
     * @param  ServerProvisioningPlan|null  $plan  Optional ordered step plan; null resolves the corresponding application plan.
     */
    public function __construct(
        private readonly Server $server,
        ?Runner $runner = null,
        ?ProvisioningScriptRenderer $renderer = null,
        ?ServerProvisioningPlan $plan = null,
    ) {
        parent::__construct($server, $runner);
        $this->renderer = $renderer ?? app(ProvisioningScriptRenderer::class);
        $this->plan = $plan ?? app(ServerProvisioningPlan::class);
    }

    /**
     * Render and launch only unfinished provisioning steps and return the validated identity of their background process.
     *
     * @return array{id: int, path: string} Positive process ID and uploaded script path for the new remote attempt.
     *
     * @throws RuntimeException If remote startup fails to return a valid process ID.
     */
    public function handle(): array
    {
        $this->script = $this->renderer->remainingServer($this->server, $this->plan);
        $this->makeScriptFile("server-{$this->server->id}-provisioning");
        $this->upload();

        $output = trim($this->run());
        if (! ctype_digit($output) || (int) $output < 1) {
            throw new RuntimeException('Remote server provisioning started without returning a valid process identifier.');
        }

        return [
            'id' => (int) $output,
            'path' => "/tmp/{$this->fileName}.sh",
        ];
    }
}

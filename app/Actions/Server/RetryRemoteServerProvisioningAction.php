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
     * @return array{id: int, path: string}
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

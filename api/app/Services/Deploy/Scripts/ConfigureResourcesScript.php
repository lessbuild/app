<?php

declare(strict_types=1);

namespace App\Services\Deploy\Scripts;

use App\Models\Build;
use App\Services\Deploy\ManagedResourceScript;

class ConfigureResourcesScript extends BuildProvisioningScript
{
    public const TITLE = 'Configure managed resources';

    public const DESCRIPTION = 'Ensure locally managed databases and cache services are available';

    public const IDENTIFIER = 'configured-resources';

    /**
     * Create a new ConfigureResourcesScript instance.
     *
     * Makes sure the environment's managed resources are running.
     *
     * @param  ManagedResourceScript  $resources  Renders the commands for each managed resource.
     */
    public function __construct(private readonly ManagedResourceScript $resources = new ManagedResourceScript) {}

    /**
     * Render installation commands for locally managed Redis, Valkey and PostgreSQL resources.
     *
     * @param  int  $step  The provisioning stage reported when these commands succeed.
     * @param  Build  $build  The build supplying the immutable environment snapshot and website identity.
     * @return string Shell source for the remote provisioning runner.
     */
    public function script(int $step, Build $build): string
    {
        $configuration = $this->resources->render($build->environment_payload['resources'] ?? []);
        $progress = $this->progress($step, $build);

        return <<<SCRIPT
        {$configuration}
        {$progress}
        SCRIPT;
    }
}

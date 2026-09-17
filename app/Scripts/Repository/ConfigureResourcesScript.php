<?php

namespace App\Scripts\Repository;

use App\Abstracts\Scripts\BuildProvisioningScript;
use App\Models\Build;
use App\Services\ManagedResourceScript;

class ConfigureResourcesScript extends BuildProvisioningScript
{
    public static string $title = 'Configure managed resources';

    public static string $description = 'Ensure locally managed databases and cache services are available';

    public static string $identifier = 'configured-resources';

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

<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Enums\Server\ServerTypeEnum;
use App\Modules\Deployer\Models\Region;
use App\Modules\Deployer\Models\Size;
use App\Modules\Deployer\Models\User;

class DashboardCreationDialogData
{
    public function __construct(
        private readonly ApplicationTemplateCatalog $templates,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * Return the workspace-scoped options required by creation dialogs hosted on the dashboard.
     *
     * @return array{
     *     server: array<string, mixed>,
     *     website: array<string, mixed>,
     *     repository: array<string, mixed>,
     *     application: array{templates: array<string, mixed>}
     * }
     */
    public function for(User $user): array
    {
        return [
            'server' => [
                'types' => ServerTypeEnum::cases(),
                'providers' => $user->workspaceProviders()->forServers()->get(),
                'regions' => Region::all(),
                'sizes' => Size::all(),
                'images' => [
                    'ubuntu-22-04-x64' => 'Ubuntu 22.04 (LTS) x64',
                    'ubuntu-20-04-x64' => 'Ubuntu 20.04 x86',
                    'ubuntu-18-04-x64' => 'Ubuntu 18.04 x86 image',
                ],
                // The dashboard is a compact quick-create surface. Keep private
                // recipe names out of its always-rendered modal markup; the
                // full recipe selector remains available on the Servers page.
                'recipes' => collect(),
                'planUsage' => $this->limits->usage($user, 'servers'),
            ],
            'website' => [
                'servers' => $user->workspaceServers()->readyForWebsites()->get(),
                'planUsage' => $this->limits->usage($user, 'websites'),
            ],
            'repository' => [
                'providers' => $user->workspaceProviders()
                    ->forRepositories()
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'websites' => $user->workspaceWebsites()
                    ->readyForDeployments()
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
            'application' => [
                'templates' => $this->templates->all(),
            ],
        ];
    }
}

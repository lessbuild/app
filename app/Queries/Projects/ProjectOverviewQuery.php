<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Data\Projects\ProjectOverview;
use App\Data\Projects\ServiceCard;
use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Platform\PlatformService;
use App\Platform\ServiceRegistry;
use Illuminate\Support\Facades\Gate;

final class ProjectOverviewQuery
{
    public function __construct(private readonly ServiceRegistry $services) {}

    public function handle(Project $project, User $viewer): ProjectOverview
    {
        $gate = Gate::forUser($viewer);
        $enabled = $project->enabledServices()->pluck('service')->all();
        $kinds = array_flip(array_map(fn (EnvironmentKind $kind): string => $kind->value, EnvironmentKind::cases()));

        return new ProjectOverview(
            project: $project,
            environments: array_values($project->environments()->get()
                ->sortBy([fn (Environment $a, Environment $b): int => $kinds[$a->kind->value] <=> $kinds[$b->kind->value], fn (Environment $a, Environment $b): int => strcasecmp($a->name, $b->name)])
                ->all()),
            services: array_map(fn (PlatformService $service): ServiceCard => new ServiceCard(
                key: $service->key(),
                name: $service->name(),
                tagline: $service->tagline(),
                icon: $service->icon(),
                enabled: in_array($service->key(), $enabled, true),
                canUse: $gate->allows('useService', [$project, $service->key()]),
                canManage: $gate->allows('manageService', [$project, $service->key()]),
                url: route('projects.services.show', [$project->id, $service->key()]),
            ), $this->services->all()),
            canManage: $gate->allows('update', $project),
        );
    }
}

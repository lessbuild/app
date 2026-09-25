<?php

namespace App\Modules\Deployer\Services;

use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Modules\Deployer\Http\Middleware\EnforceOrganizationSecurity;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ControlPlaneAccess
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly EnforceOrganizationSecurity $security,
    ) {}

    /**
     * Enforce API entitlement, workspace network policy, and a token ability before request validation.
     *
     * @param  Request  $request  Authenticated control-plane request.
     * @param  string  $ability  Required Sanctum ability, such as read, deploy, or manage.
     */
    public function enforce(Request $request, string $ability): void
    {
        /** @var User $user */
        $user = $request->user();
        $scope = $this->scopedTokenClaims($user);
        abort_unless(app(MappedProjectResourceAccess::class)->deniedResourceIds(
            $user, 'deployer', 'project', 'organization', (string) $user->current_organization_id, [],
        ) !== null, 403, 'This Buildpusher workspace is not available to this token.');

        if ($scope !== null) {
            abort_unless((string) $user->current_organization_id === $scope['workspace_id'], 403, 'This token is scoped to another workspace.');

            if ($scope['project_ids'] !== null) {
                $projectId = $this->projectIdFromRequest($request);
                if ($projectId !== null) {
                    $this->enforceProject($request, $projectId);
                } elseif ($this->hasProjectBoundResource($request)) {
                    abort(403, 'This token is scoped to other projects.');
                }
            }
        }

        $this->entitlements->enforce($user, 'api');
        $ranges = $user->currentOrganization?->allowed_ip_ranges ?? [];
        abort_if($ranges !== [] && ! collect($ranges)->contains(fn (string $range): bool => $this->security->contains($range, (string) $request->ip())), 403, 'This network is not allowed by the workspace security policy.');
        abort_unless($user->tokenCan($ability), 403, "Token lacks the {$ability} ability.");
    }

    /**
     * Return the project allowlist for a project-scoped personal token, or null for workspace-wide, legacy, and first-party requests.
     *
     * @return ?list<int>
     */
    public function projectIds(Request $request): ?array
    {
        $user = $request->user();

        return $user instanceof User ? $this->scopedTokenClaims($user)['project_ids'] ?? null : null;
    }

    /** Enforce an explicit project allowlist for a target supplied outside the route path. */
    public function enforceProject(Request $request, int|string $projectId): void
    {
        $user = $request->user();
        $projectIds = $user instanceof User ? $this->scopedTokenClaims($user)['project_ids'] ?? null : null;
        $project = Project::query()->find($projectId);
        abort_unless($user instanceof User && $project !== null
            && app(DeployerProjectAccess::class)->project($user, $project), 403, 'This project is not available to this token.');

        if ($projectIds !== null) {
            abort_unless(in_array((int) $projectId, $projectIds, true), 403, 'This token is scoped to other projects.');
        }
    }

    /** @return ?array{workspace_id: string, project_ids: ?list<int>} */
    private function scopedTokenClaims(User $user): ?array
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        $workspaceClaims = [];
        $projectClaims = [];

        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                continue;
            }

            if (str_starts_with($ability, 'workspace:')) {
                $workspaceClaims[] = substr($ability, strlen('workspace:'));
            }

            if (str_starts_with($ability, 'project:')) {
                $projectClaims[] = substr($ability, strlen('project:'));
            }
        }

        if ($workspaceClaims === [] && $projectClaims === []) {
            return null;
        }

        abort_unless(count($workspaceClaims) === 1 && ctype_digit($workspaceClaims[0]), 403, 'This API token has invalid workspace scope.');
        abort_unless(collect($projectClaims)->every(fn (string $projectId): bool => ctype_digit($projectId)), 403, 'This API token has invalid project scope.');

        $projectIds = array_values(array_unique(array_map('intval', $projectClaims)));

        return [
            'workspace_id' => $workspaceClaims[0],
            'project_ids' => $projectIds === [] ? null : $projectIds,
        ];
    }

    private function projectIdFromRequest(Request $request): ?int
    {
        $project = $request->route('project');
        if ($project instanceof Project) {
            return (int) $project->getKey();
        }
        if (is_numeric($project)) {
            return (int) $project;
        }

        $environment = $request->route('environment');
        if ($environment instanceof Environment) {
            return (int) $environment->project_id;
        }
        if (is_numeric($environment)) {
            $projectId = Environment::query()->whereKey($environment)->value('project_id');

            return $projectId === null ? null : (int) $projectId;
        }

        $build = $request->route('build');
        if ($build instanceof Build) {
            $projectId = $build->environment()->value('project_id');

            return $projectId === null ? null : (int) $projectId;
        }
        if (is_numeric($build)) {
            $projectId = Build::query()->with('environment:id,project_id')->find($build)?->environment?->project_id;

            return $projectId === null ? null : (int) $projectId;
        }

        return null;
    }

    private function hasProjectBoundResource(Request $request): bool
    {
        return $request->route('project') !== null
            || $request->route('environment') !== null
            || $request->route('build') !== null;
    }
}

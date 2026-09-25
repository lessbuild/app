<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Services\DeployerMutationClaimManager;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rejects new authenticated mutations for a Deployer principal or workspace already fenced for deletion. */
final class EnsureDeployerDeletionFence
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            $workspaceId = $this->routeWorkspaceId($request);
            if ($workspaceId !== null) {
                $fenced = ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', $workspaceId)->exists();
                // Signed callbacks complete claims created before the fence; workers reject new claims themselves.
                abort_if($fenced && ! $request->routeIs('callbacks.*'), 409, 'This workspace is being deleted.');
            }
            $user = $request->user();
            if ($user instanceof User) {
                abort_if(ProductDeletionFence::query()->where('kind', 'account')->where('source_id', (string) $user->getKey())->exists(), 409, 'This account is being deleted.');
                $organization = $user->currentOrganization;
                if ($organization instanceof Organization) {
                    abort_if(ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', (string) $organization->getKey())->exists(), 409, 'This workspace is being deleted.');
                }
            }

            if (! $request->routeIs('callbacks.*')) {
                $claims = app(DeployerMutationClaimManager::class);
                $claimGroupId = $claims->claimRequest($request);
                try {
                    $response = $next($request);
                } catch (ValidationException|AuthorizationException $exception) {
                    if ($claimGroupId !== null) {
                        $claims->complete($claimGroupId);
                    }

                    throw $exception;
                } catch (\Throwable $exception) {
                    // A failed handler may have committed work or remote effects. Keep the claim for operator review.
                    throw $exception;
                }
                if ($claimGroupId !== null && $response->getStatusCode() < 500 && ! $response instanceof StreamedResponse) {
                    $claims->complete($claimGroupId);
                }

                return $response;
            }
        }

        return $next($request);
    }

    private function routeWorkspaceId(Request $request): ?string
    {
        foreach (['organization', 'server', 'website', 'repository', 'project', 'environment', 'build'] as $parameter) {
            $resource = $request->route($parameter);
            if ($resource instanceof Organization) {
                return (string) $resource->getKey();
            }
            if ($resource instanceof Server || $resource instanceof Website || $resource instanceof Repository) {
                return $resource->organization_id === null ? null : (string) $resource->organization_id;
            }
            if ($resource instanceof Project) {
                return (string) $resource->organization_id;
            }
            if ($resource instanceof Environment) {
                return $resource->project?->organization_id === null ? null : (string) $resource->project->organization_id;
            }
            if ($resource instanceof Build) {
                return $resource->repository?->organization_id === null ? null : (string) $resource->repository->organization_id;
            }
        }

        return null;
    }
}

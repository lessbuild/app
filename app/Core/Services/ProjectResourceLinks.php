<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectResourceLinks
{
    public function __construct(private readonly ProjectResourceLinkRegistry $providers) {}

    /** @param list<string> $products
     * @return Collection<string, list<ProjectResourceCandidate>>
     */
    public function candidates(PlatformUser $user, array $products): Collection
    {
        $candidates = collect();

        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);

            if ($provider === null) {
                continue;
            }

            try {
                $available = $provider->candidates($user);
            } catch (LostConnectionException|QueryException) {
                continue;
            }

            if ($available !== []) {
                $candidates->put($product, $available);
            }
        }

        return $candidates;
    }

    public function link(
        PlatformUser $user,
        Project $project,
        string $product,
        string $selectionKey,
        ?string $environmentId = null,
    ): ?ProjectResource {
        $provider = $this->providers->get($product);

        if ($provider === null) {
            return null;
        }

        try {
            $candidate = $provider->candidate($user, $selectionKey);
        } catch (LostConnectionException|QueryException) {
            return null;
        }

        if ($candidate === null) {
            return null;
        }

        try {
            return DB::connection('core')->transaction(function () use ($user, $project, $product, $candidate, $environmentId): ?ProjectResource {
                $environment = $environmentId === null
                    ? null
                    : ProjectEnvironment::query()
                        ->where('project_id', $project->getKey())
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->find($environmentId);

                if ($environmentId !== null && $environment === null) {
                    return null;
                }

                if ($candidate->resourceType === 'environment' && $environment === null) {
                    throw ValidationException::withMessages([
                        'environment_id' => __('Choose the canonical project environment that matches this application environment.'),
                    ]);
                }

                $alreadyLinked = ProjectResource::query()
                    ->where('product', $product)
                    ->where('resource_type', $candidate->resourceType)
                    ->where('resource_id', $candidate->id)
                    ->exists();

                if ($alreadyLinked) {
                    return null;
                }

                $now = now();
                $projectProduct = ProjectProduct::query()->firstOrNew([
                    'project_id' => $project->getKey(),
                    'product' => $product,
                ]);
                $projectProduct->forceFill([
                    'status' => 'active',
                    'requested_by_user_id' => $user->getKey(),
                    'activated_at' => $projectProduct->activated_at ?? $now,
                    'last_error_code' => null,
                    'last_error_at' => null,
                ])->save();

                return ProjectResource::query()->create([
                    'project_id' => $project->getKey(),
                    'environment_id' => $environment?->getKey(),
                    'product' => $product,
                    'resource_type' => $candidate->resourceType,
                    'resource_id' => $candidate->id,
                    'name' => $candidate->name,
                    'status' => 'active',
                    'mapped_at' => $now,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}

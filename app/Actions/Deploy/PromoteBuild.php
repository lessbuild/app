<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Repository;
use App\Models\User;
use App\Services\Deploy\Deployments;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PromoteBuild
{
    /**
     * Deploys a build's commit to a higher environment.
     *
     * @param  Deployments  $deployments  Queues the promotion, or says why it can't be queued now.
     */
    public function __construct(private readonly Deployments $deployments) {}

    /**
     * Ship a succeeded build's exact commit to a later environment of the same project (staging to production, say),
     * through that environment's repository for the same Git source. Its approval, locks and windows apply.
     */
    public function handle(User $actor, Build $source, Environment $target, ?string $note = null): Build
    {
        Gate::forUser($actor)->authorize('view', $source);
        $origin = $source->environment;
        if ($source->status !== Build::STATUS_SUCCEEDED || preg_match('/\A[0-9a-f]{40,64}\z/D', (string) $source->revision) !== 1 || $origin === null) {
            throw ValidationException::withMessages(['promote' => __('Only a live build of a known commit in an environment can be promoted.')]);
        }
        if ($target->project_id !== $origin->project_id || $target->id === $origin->id || $target->kind->rank() <= $origin->kind->rank()) {
            throw ValidationException::withMessages(['promote' => __('Promote to a later environment of the same project.')]);
        }
        $repository = Repository::query()->with(['provider', 'website.server', 'environment'])->where('environment_id', $target->id)
            ->where('url', $source->repository->url)->get()
            ->first(fn (Repository $candidate): bool => $candidate->provider?->type === $source->repository->provider?->type);
        if ($repository === null) {
            throw ValidationException::withMessages(['promote' => __(':environment has no repository for :url.', ['environment' => $target->name, 'url' => $source->repository->url])]);
        }
        Gate::forUser($actor)->authorize('deploy', $repository);
        if (! $repository->isDeploymentReady()) {
            throw ValidationException::withMessages(['promote' => __('The target website must be live on an active server.')]);
        }
        $blocked = $this->deployments->blockReason($repository);
        if ($blocked !== null) {
            throw ValidationException::withMessages(['promote' => $blocked]);
        }
        $build = $this->deployments->queue($repository, [
            'trigger_source' => 'promotion', 'revision' => $source->revision, 'commit_message' => $source->commit_message,
            'promoted_from_build_id' => $source->id, 'promotion_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 2000),
        ], $actor);

        return $build ?? throw new StateConflict(__('A deploy to :website is already running.', ['website' => $repository->website->name]));
    }
}

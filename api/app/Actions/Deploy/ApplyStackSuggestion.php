<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Repository;
use App\Models\User;
use App\Services\Deploy\StackSuggestions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApplyStackSuggestion
{
    /**
     * Create a new ApplyStackSuggestion instance.
     *
     * Adds what a repository's app needs to its environment.
     *
     * @param  StackSuggestions  $suggestions  Works out what's still missing, so a stale page can't add twice.
     * @param  SaveEnvironmentProcess  $processes  Adds workers and the scheduler within the plan.
     * @param  SaveEnvironmentResource  $resources  Adds managed Redis or the database within the plan.
     */
    public function __construct(private readonly StackSuggestions $suggestions, private readonly SaveEnvironmentProcess $processes, private readonly SaveEnvironmentResource $resources) {}

    /**
     * Apply one suggestion by its key.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  string  $key
     * @return void
     */
    public function handle(User $actor, Repository $repository, string $key): void
    {
        $environment = $repository->environment;
        if ($environment === null) {
            throw ValidationException::withMessages(['key' => __('Choose the repository’s environment first.')]);
        }
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $suggestion = collect($this->suggestions->suggestions($repository))->first(fn ($suggestion): bool => $suggestion->key === $key && $suggestion->fixable());
        if ($suggestion === null) {
            throw ValidationException::withMessages(['key' => __('That’s already done.')]);
        }
        if ($suggestion->process !== null) {
            $this->processes->handle($actor, $environment, [...$suggestion->process, 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true]);
        }
        if ($suggestion->resource !== null) {
            $this->resources->handle($actor, $environment, [...$suggestion->resource, 'is_managed' => true, 'variables' => null, 'database_website_id' => $suggestion->resource['type'] === 'mysql' ? $repository->website_id : null]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use App\Services\Deploy\Configuration\ConfigurationPlanner;
use Illuminate\Support\Facades\Gate;

final class CreateConfigurationReview
{
    /**
     * Create a new CreateConfigurationReview instance.
     *
     * Opens a review of a configuration document.
     *
     * @param  ConfigurationPlanner  $planner  Works out the changes the review will show.
     */
    public function __construct(private readonly ConfigurationPlanner $planner) {}

    /**
     * Freeze a document's plan for 15 minutes so its requester can apply exactly that.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $document
     * @param  array<string, mixed>  $bindings
     * @return ConfigurationReview
     */
    public function handle(User $actor, Project $project, string $document, array $bindings): ConfigurationReview
    {
        Gate::forUser($actor)->authorize('manageDeploy', $project);
        $review = new ConfigurationReview;
        $review->forceFill([
            'project_id' => $project->id, 'requested_by' => $actor->id, 'document' => $document, 'bindings' => $bindings,
            'summary' => $this->planner->plan($project, $actor, $document, $bindings), 'expires_at' => now()->addMinutes(15),
        ])->save();

        return $review;
    }
}

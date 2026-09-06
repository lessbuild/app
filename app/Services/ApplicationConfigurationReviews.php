<?php

namespace App\Services;

use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationReviews
{
    public function __construct(private readonly ApplicationConfigurationPlanner $planner) {}

    public function create(Project $project, User $user, string $yaml, array $bindings): ConfigurationReview
    {
        $summary = $this->planner->plan($project, $user, $yaml, $bindings);

        return ConfigurationReview::query()->create([
            'project_id' => $project->id,
            'requested_by' => $user->id,
            'document' => $yaml,
            'bindings' => $bindings,
            'summary' => $summary,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /** Revalidate access and expiry; this does not apply or consume the review. */
    public function inspect(ConfigurationReview $review, User $user): array
    {
        $review = ConfigurationReview::query()->findOrFail($review->id);
        if ((int) $review->requested_by !== (int) $user->id
            || (int) $review->project->organization_id !== (int) $user->current_organization_id
            || ! $review->project->organization->permits($user, 'manage')) {
            throw new AuthorizationException;
        }
        if ($review->applied_at !== null || ! $review->expires_at || $review->expires_at->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['review' => 'This review has expired or has already been applied. Create a new review.']);
        }

        $current = $this->planner->plan($review->project, $user, $review->document, $review->bindings);
        if (! hash_equals((string) ($review->summary['fingerprint'] ?? ''), $current['fingerprint'])) {
            throw ValidationException::withMessages(['review' => 'Configuration changed after this review. Create a new review.']);
        }

        return $current;
    }
}

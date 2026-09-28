<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use App\Models\ConfigurationReview;
use Illuminate\Validation\ValidationException;

/** Checks a review is still applicable: unapplied, not past its 15 minutes, and planning it again gives the same fingerprint. */
final class ConfigurationReviews
{
    /**
     * Checks reviews are still current.
     *
     * @param  ConfigurationPlanner  $planner  Plans the review's document again.
     */
    public function __construct(private readonly ConfigurationPlanner $planner) {}

    /**
     * The review's plan, if the review is unapplied, unexpired, and nothing it depends on changed since.
     *
     * @param  ConfigurationReview  $review
     * @return array{version: int, project_id: string, changes: list<array<string, mixed>>, fingerprint: string, omitted_objects: string, apply_available: bool}
     */
    public function current(ConfigurationReview $review): array
    {
        if ($review->applied_at !== null || $review->expires_at->isPast()) {
            throw ValidationException::withMessages(['review' => 'This review has expired or was already applied. Create a new review.']);
        }
        $plan = $this->planner->plan($review->project, $review->requester, $review->document, $review->bindings);
        if (! hash_equals((string) $review->summary['fingerprint'], $plan['fingerprint'])) {
            throw ValidationException::withMessages(['review' => 'The configuration changed after this review. Create a new review.']);
        }

        return $plan;
    }
}

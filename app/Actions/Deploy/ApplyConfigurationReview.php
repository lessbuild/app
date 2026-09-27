<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationReview;
use App\Models\Project;
use App\Models\User;
use App\Services\Deploy\Configuration\ConfigurationOperations;
use App\Services\Deploy\Configuration\ConfigurationReconciler;
use App\Services\Deploy\Configuration\ConfigurationReviews;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApplyConfigurationReview
{
    public function __construct(private readonly ConfigurationReviews $reviews, private readonly ConfigurationReconciler $reconciler, private readonly ConfigurationOperations $operations) {}

    /**
     * Apply a review, once, as its requester: the project is locked, the plan re-checked against the fingerprint, local
     * changes made in one transaction, and then its deploys start. Applying again returns the same receipt.
     */
    public function handle(User $actor, ConfigurationReview $review): ConfigurationApplication
    {
        Gate::forUser($actor)->authorize('manageDeploy', $review->project);
        $application = DB::transaction(function () use ($actor, $review): ConfigurationApplication {
            // SQLite ignores FOR UPDATE: take its write lock first so a competing apply waits instead of reading stale state.
            Project::query()->whereKey($review->project_id)->update(['updated_at' => DB::raw('updated_at')]);
            Project::query()->lockForUpdate()->findOrFail($review->project_id);
            $review = ConfigurationReview::query()->with(['project', 'requester'])->lockForUpdate()->findOrFail($review->id);
            if ($review->requested_by !== $actor->id) {
                throw new AuthorizationException(__('Only the person who created a review can apply it.'));
            }
            $existing = ConfigurationApplication::query()->where('configuration_review_id', $review->id)->first();
            if ($existing !== null && $review->applied_at !== null) {
                return $existing;
            }
            $plan = $this->reviews->current($review);
            if (collect($plan['changes'])->contains('action', 'adoption_required')) {
                throw ValidationException::withMessages(['review' => __('Objects that exist but configuration doesn’t own need `adopt: true` before this can be applied.')]);
            }
            $application = new ConfigurationApplication;
            $application->forceFill(['configuration_review_id' => $review->id, 'status' => 'applying'])->save();
            $this->reconciler->apply($review, $application, $actor);
            $application->forceFill(['status' => $application->relatedOperations()->exists() ? 'awaiting_dispatch' : 'locally_applied', 'locally_applied_at' => now()])->save();
            $review->forceFill(['applied_at' => now()])->save();

            return $application;
        }, 5);
        $application->relatedOperations()->whereIn('status', ['pending', 'blocked'])->get()->each(fn ($operation) => $this->operations->deliver($operation));

        return $this->operations->refresh($application);
    }
}

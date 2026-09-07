<?php

namespace App\Services;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationReview;
use App\Models\EnvironmentVariable;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationConfigurationTransaction
{
    /**
     * Bind review revalidation used inside the configuration transaction.
     *
     * @param  ApplicationConfigurationReviews  $reviews  Checks immutable review identity, access and state freshness.
     */
    public function __construct(private readonly ApplicationConfigurationReviews $reviews) {}

    /** Internal transaction boundary. The writer must only perform local database writes,
     * including durable remote-operation intents; never perform network work here.
     * Locks are acquired before revalidating the reviewed state.
     */
    public function run(ConfigurationReview $review, User $user, Closure $writer): ConfigurationApplication
    {
        return DB::transaction(function () use ($review, $user, $writer) {
            $project = ApplicationConfigurationLocks::project($review->project_id);
            $review = ConfigurationReview::query()->lockForUpdate()->findOrFail($review->id);
            $user = User::query()->findOrFail($user->id);
            if ((int) $review->project_id !== (int) $project->id
                || (int) $review->requested_by !== (int) $user->id
                || (int) $project->organization_id !== (int) $user->current_organization_id
                || ! $project->organization->permits($user, 'manage')) {
                throw new AuthorizationException;
            }
            $application = ConfigurationApplication::query()->where('configuration_review_id', $review->id)->first();
            if ($application && $review->applied_at && $application->locally_applied_at) {
                // An authorized retry returns the original receipt, even after review expiry.
                return $application;
            }
            // Stabilize existing records used by the planner before its freshness check.
            // Project locking serializes configuration applies, while resource locks
            // also exclude ordinary updates to these existing rows.
            $environments = $project->environments()->orderBy('id')->lockForUpdate()->get();
            foreach ($environments as $environment) {
                foreach (['processes', 'resources', 'variables'] as $relation) {
                    $environment->{$relation}()->orderBy('id')->lockForUpdate()->get();
                }
            }
            $websites = Website::query()->where('organization_id', $project->organization_id)
                ->whereIn('id', array_merge($environments->pluck('website_id')->filter()->all(), array_values($review->bindings['placements'] ?? [])))
                ->orderBy('id')->lockForUpdate()->get();
            Server::query()->whereIn('id', $websites->pluck('server_id'))->orderBy('id')->lockForUpdate()->get();
            EnvironmentVariable::query()->whereHas('environment.project', fn ($query) => $query->where('organization_id', $project->organization_id))
                ->whereIn('id', array_values($review->bindings['secrets'] ?? []))
                ->orderBy('id')->lockForUpdate()->get();
            Repository::query()->where('organization_id', $project->organization_id)
                ->whereIn('id', array_values($review->bindings['repositories'] ?? []))
                ->orderBy('id')->lockForUpdate()->get();
            $plan = $this->reviews->inspect($review, $user);
            if (collect($plan['changes'])->contains('action', 'adoption_required')) {
                throw ValidationException::withMessages(['review' => 'Explicit adoption is required before applying this configuration.']);
            }
            $application = ConfigurationApplication::query()->create([
                'configuration_review_id' => $review->id, 'status' => 'applying',
            ]);
            $writer($review, $application);
            $application->update(['status' => $application->relatedOperations()->exists() ? 'awaiting_dispatch' : 'locally_applied', 'locally_applied_at' => now()]);
            $review->update(['applied_at' => now()]);

            return app(ApplicationConfigurationResults::class)->refresh($application);
        }, 5);
    }
}

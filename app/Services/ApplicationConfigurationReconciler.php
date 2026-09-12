<?php

namespace App\Services;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOwnership;
use App\Models\ConfigurationReview;
use App\Models\User;

class ApplicationConfigurationReconciler
{
    /**
     * Bind the services that atomically apply an immutable reviewed configuration.
     *
     * @param  ApplicationConfigurationTransaction  $transactions  Locks and revalidates review/project state around local changes.
     * @param  ApplicationConfigurationDocument  $documents  Parses the review's saved configuration.
     * @param  ApplicationConfigurationBindings  $bindings  Resolves and verifies the saved binding identities.
     * @param  ApplicationConfigurationEnvironmentReconciler  $environments  Converges one reviewed environment and its owned children.
     */
    public function __construct(
        private readonly ApplicationConfigurationTransaction $transactions,
        private readonly ApplicationConfigurationDocument $documents,
        private readonly ApplicationConfigurationBindings $bindings,
        private readonly ApplicationConfigurationEnvironmentReconciler $environments,
    ) {}

    /** Local reconciliation only. Does not claim that remote services have been deployed. */
    public function apply(ConfigurationReview $review, User $user): ConfigurationApplication
    {
        return $this->transactions->run($review, $user, function (ConfigurationReview $review, ConfigurationApplication $application) use ($user): void {
            $document = $this->documents->parse($review->document);
            $project = $review->project;
            $resolved = $this->bindings->resolve($project, $user, $document, $review->bindings);
            foreach ($document['environments'] as $slug => $desired) {
                $this->environments->reconcile($review, $application, $project, $user, $slug, $desired, $resolved);
            }
            foreach ($document['remove']['environments'] ?? [] as $slug) {
                // The transaction revalidates every owned child and dependency before
                // reaching this point. FK cascades remove only reviewed local records.
                $environment = $project->environments()->where('slug', $slug)->first();
                if ($environment) {
                    $environment->delete();
                    ConfigurationOwnership::query()->where('project_id', $project->id)
                        ->where('environment_slug', $slug)->delete();
                }
            }
        });
    }
}

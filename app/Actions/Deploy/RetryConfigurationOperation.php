<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\User;
use App\Services\Deploy\Configuration\ConfigurationOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class RetryConfigurationOperation
{
    /**
     * Retries a failed configuration operation.
     *
     * @param  ConfigurationOperations  $operations  Starts the retry and refreshes its application's status.
     */
    public function __construct(private readonly ConfigurationOperations $operations) {}

    /** Retry a failed or canceled configuration deploy as it was reviewed. Only the review's requester can. */
    public function handle(User $actor, ConfigurationApplication $application, ConfigurationOperation $operation): ConfigurationOperation
    {
        Gate::forUser($actor)->authorize('manageDeploy', $application->review->project);
        if ($application->review->requested_by !== $actor->id) {
            throw new AuthorizationException(__('Only the person who applied this configuration can retry its deploys.'));
        }
        $retry = $this->operations->retry($operation);
        $this->operations->refresh($application);

        return $retry;
    }
}

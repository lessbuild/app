<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\User;
use App\Services\Deploy\Configuration\ConfigurationOperations;
use Illuminate\Support\Facades\Gate;

final class CancelConfigurationOperation
{
    /**
     * Cancels a configuration operation that hasn't finished.
     *
     * @param  ConfigurationOperations  $operations  Cancels it and refreshes its application's status.
     */
    public function __construct(private readonly ConfigurationOperations $operations) {}

    /**
     * Cancel a configuration deploy that hasn't started on the server.
     *
     * @param  User  $actor
     * @param  ConfigurationApplication  $application
     * @param  ConfigurationOperation  $operation
     * @return ConfigurationOperation
     */
    public function handle(User $actor, ConfigurationApplication $application, ConfigurationOperation $operation): ConfigurationOperation
    {
        Gate::forUser($actor)->authorize('manageDeploy', $application->review->project);
        $canceled = $this->operations->cancel($operation);
        $this->operations->refresh($application);

        return $canceled;
    }
}

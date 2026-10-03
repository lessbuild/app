<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\IssueTracker;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveIssueTracker
{
    /**
     * Disconnect a ticket tracker (tickets already filed keep their links).
     *
     * @param  User  $actor
     * @param  IssueTracker  $tracker
     * @return void
     */
    public function handle(User $actor, IssueTracker $tracker): void
    {
        Gate::forUser($actor)->authorize('manageService', [$tracker->project, 'monitoring']);
        $tracker->delete();
    }
}

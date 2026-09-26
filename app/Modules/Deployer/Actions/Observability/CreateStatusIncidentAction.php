<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\StatusSubscriberNotifier;

class CreateStatusIncidentAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly StatusSubscriberNotifier $notifier,
    ) {}

    /**
     * Create a scoped status update, set its initial resolution timestamp, and notify subscribers.
     *
     * @param  Organization  $organization  Workspace that owns the selected status page.
     * @param  User  $actor  Account recorded as the incident creator.
     * @param  array<string, mixed>  $attributes  Validated incident fields.
     */
    public function handle(Organization $organization, User $actor, array $attributes): StatusIncident
    {
        $this->entitlements->enforce($organization, 'status_pages');
        $page = $organization->statusPages()->findOrFail($attributes['status_page_id']);
        abort_unless($actor->can('update', $page), 403);
        $incident = $page->incidents()->create([
            ...collect($attributes)->except('status_page_id')->all(),
            'created_by' => $actor->id,
            'resolved_at' => in_array($attributes['status'], ['resolved', 'completed'], true) ? now() : null,
        ]);
        $this->notifier->send($incident);

        return $incident;
    }
}

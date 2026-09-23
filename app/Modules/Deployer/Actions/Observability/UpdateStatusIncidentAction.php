<?php

namespace App\Modules\Deployer\Actions\Observability;

use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Services\Entitlements;
use App\Modules\Deployer\Services\StatusSubscriberNotifier;

class UpdateStatusIncidentAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly StatusSubscriberNotifier $notifier,
    ) {}

    /**
     * Update a status incident, maintain resolution timestamps, and notify subscribers immediately.
     *
     * @param  array<string, mixed>  $attributes  Validated incident fields.
     */
    public function handle(StatusIncident $incident, array $attributes): StatusIncident
    {
        $this->entitlements->enforce($incident->statusPage->organization, 'status_pages');
        $incident->update([
            ...collect($attributes)->except('status_page_id')->all(),
            'resolved_at' => in_array($attributes['status'], ['resolved', 'completed'], true)
                ? ($incident->resolved_at ?? now()) : null,
        ]);
        $incident = $incident->fresh();
        $this->notifier->send($incident);

        return $incident;
    }
}

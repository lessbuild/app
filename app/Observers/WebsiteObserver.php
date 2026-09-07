<?php

namespace App\Observers;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Website;
use App\Services\ActivityRecorder;
use App\Services\IncidentNotifier;

class WebsiteObserver
{
    /**
     * Coordinate website activity history and provisioning incident notifications.
     *
     * @param  ActivityRecorder  $activity  Recorder for attributed lifecycle events in the application activity stream.
     * @param  IncidentNotifier  $incidents  Incident service used for lifecycle failure and recovery notifications.
     */
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly IncidentNotifier $incidents,
    ) {}

    /**
     * Record website creation in the owner's activity history.
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     */
    public function created(Website $website): void
    {
        $this->record($website, "Website \"{$website->name}\" was created.");
    }

    /**
     * Record provisioning transitions and open or recover provisioning incidents for failed or active states.
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     */
    public function updated(Website $website): void
    {
        if ($website->wasChanged('provisioning_status')) {
            $this->record($website, "Website \"{$website->name}\" is {$website->provisioning_status}.");

            if ($website->provisioning_status === Website::STATUS_FAILED) {
                if ($website->user) {
                    $this->incidents->fail(
                        $website->user,
                        'website',
                        $website->id,
                        "Website \"{$website->name}\" failed",
                        $website->provisioning_error ?: 'Website provisioning failed before it completed.',
                    );
                }
            } elseif ($website->provisioning_status === Website::STATUS_ACTIVE && $website->user) {
                $this->incidents->recoverIfOpen(
                    $website->user,
                    'website',
                    $website->id,
                    "Website \"{$website->name}\" recovered",
                    __('Website provisioning completed successfully.'),
                );
            }
        }
    }

    /**
     * After a soft deletion, record the event and queue remote placement cleanup; skip force deletion.
     *
     * When a website is deleted
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     */
    public function deleted(Website $website): void
    {
        if ($website->isForceDeleting()) {
            return;
        }

        $this->record($website, "Website \"{$website->name}\" was deleted.");
        DeleteWebsiteFromCaddyJob::dispatch($website->id);
    }

    /**
     * Store website activity when the website has an owning user.
     *
     * @param  Website  $website  Website supplying its provisioning state and managed placement.
     * @param  string  $message  Human-readable lifecycle event to retain in the activity stream.
     */
    private function record(Website $website, string $message): void
    {
        if ($website->user_id) {
            $this->activity->record($website, $website->user_id, 'website', $message);
        }
    }
}

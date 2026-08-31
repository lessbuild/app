<?php

namespace App\Observers;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Website;
use App\Services\ActivityRecorder;
use App\Services\IncidentNotifier;

class WebsiteObserver
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly IncidentNotifier $incidents,
    ) {}

    public function created(Website $website): void
    {
        $this->record($website, "Website \"{$website->name}\" was created.");
    }

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
     * When a website is deleted
     */
    public function deleted(Website $website): void
    {
        if ($website->isForceDeleting()) {
            return;
        }

        $this->record($website, "Website \"{$website->name}\" was deleted.");
        DeleteWebsiteFromCaddyJob::dispatch($website->id);
    }

    private function record(Website $website, string $message): void
    {
        if ($website->user_id) {
            $this->activity->record($website, $website->user_id, 'website', $message);
        }
    }
}

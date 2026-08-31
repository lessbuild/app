<?php

namespace App\Observers;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Website;
use App\Notifications\FailureNotification;
use App\Services\ActivityRecorder;

class WebsiteObserver
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function created(Website $website): void
    {
        $this->record($website, "Website \"{$website->name}\" was created.");
    }

    public function updated(Website $website): void
    {
        if ($website->wasChanged('provisioning_status')) {
            $this->record($website, "Website \"{$website->name}\" is {$website->provisioning_status}.");

            if ($website->provisioning_status === Website::STATUS_FAILED) {
                $website->user?->notify(new FailureNotification(
                    'website',
                    $website->id,
                    "Website \"{$website->name}\" failed",
                    $website->provisioning_error ?: 'Website provisioning failed before it completed.',
                ));
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

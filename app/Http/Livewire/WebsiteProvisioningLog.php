<?php

namespace App\Http\Livewire;

use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class WebsiteProvisioningLog extends Component
{
    public Website $website;

    public function render(): View
    {
        $this->website->refresh();
        Gate::authorize('view', $this->website);

        $log = $this->website->logs()
            ->where('type', Website::PROVISIONING_LOG_TYPE)
            ->first();

        return view('livewire.website-provisioning-log', [
            'hasLog' => $log !== null,
            'lines' => $log ? explode(PHP_EOL, $log->log) : [],
            'updatedAt' => $log?->updated_at,
            'shouldPoll' => in_array($this->website->provisioning_status, [
                Website::STATUS_QUEUED,
                Website::STATUS_PROVISIONING,
            ], true),
        ]);
    }
}

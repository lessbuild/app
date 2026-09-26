<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\PublicWebhookTarget;

class ChangeAlertDestinationRequest extends SaveAlertDestinationRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(CurrentWorkspace $currentWorkspace, PublicWebhookTarget $targets): array
    {
        return ['version' => ['required', 'integer', 'min:0']];
    }
}

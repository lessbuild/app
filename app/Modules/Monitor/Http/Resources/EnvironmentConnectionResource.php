<?php

namespace App\Modules\Monitor\Http\Resources;

use App\Modules\Monitor\Models\Environment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Environment */
class EnvironmentConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'environment_id' => $this->id,
            'state' => $this->status === 'paused' ? 'paused' : ($this->last_seen_at === null ? 'awaiting_events' : 'event_received'),
            'event_count' => $this->event_count,
            'last_received_at' => $this->last_seen_at?->toIso8601String(),
            'active_tokens' => $this->active_token_count,
        ];
    }
}

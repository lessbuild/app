<?php

namespace App\Modules\Monitor\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HeartbeatReceiptResource extends JsonResource
{
    /** @return array{run_id: string, signal: string, replayed: bool, received_at: string} */
    public function toArray(Request $request): array
    {
        return ['run_id' => $this->resource['run_id'], 'signal' => $this->resource['signal'],
            'replayed' => $this->resource['replayed'], 'received_at' => $this->resource['received_at']];
    }
}

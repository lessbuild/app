<?php

namespace App\Modules\Monitor\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class QueueSignalReceiptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource, ['snapshot_id', 'worker_id', 'sequence', 'status', 'replayed', 'applied', 'received_at']);
    }
}

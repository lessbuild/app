<?php

namespace App\Modules\Monitor\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngestReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'submitted' => $this->event_count,
            'accepted' => $this->accepted_count,
            'duplicates' => $this->duplicate_count,
            'attempts' => $this->attempt_count,
            'received_at' => $this->received_at->toISOString(),
            'last_received_at' => $this->last_received_at->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'processing' => [
                'attempts' => $this->processing_attempts,
                'recoveries' => $this->recovery_count,
                'next_attempt_at' => $this->next_attempt_at?->toISOString(),
                'failed_at' => $this->failed_at?->toISOString(),
                'error_code' => $this->processingError() === null ? null : $this->last_error_code,
                'error_message' => $this->processingError(),
            ],
        ];
    }
}

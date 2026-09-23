<?php

namespace App\Modules\Monitor\Http\Resources;

use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngestBatchResource extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Cache-Control', 'no-store, private');
        $response->setStatusCode($this->status === IngestStatus::Completed ? 200 : 202);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->batchId,
            'accepted' => $this->accepted,
            'duplicates' => $this->duplicates,
            'receipt_id' => $this->receiptId,
            'replayed' => $this->replayed,
            'status' => $this->status->value,
            'message' => $this->status === IngestStatus::Completed ? 'Telemetry batch accepted.' : 'Telemetry delivery retained for background processing.',
        ];
    }
}

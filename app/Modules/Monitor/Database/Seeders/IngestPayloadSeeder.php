<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\IngestPayload;
use App\Modules\Monitor\Services\Telemetry\TelemetryQueue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IngestPayloadSeeder extends Seeder
{
    public function run(TelemetryQueue $queue): void
    {
        DB::connection('monitor')->transaction(function () use ($queue): void {
            $payload = IngestPayload::factory()->create();
            $queue->dispatch($payload->ingestReceipt);
        });
    }
}

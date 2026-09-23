<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Contracts\TelemetryIngestor;
use App\Modules\Monitor\Models\Application;
use Illuminate\Database\Seeder;

class IngestReceiptSeeder extends Seeder
{
    public function run(TelemetryIngestor $ingestor): void
    {
        $application = Application::factory()->create(['name' => 'Receipt demo']);
        $environment = $application->environments()->create(['name' => 'Demo', 'slug' => 'demo', 'status' => 'active']);
        $events = [['id' => 'receipt-example', 'type' => 'log', 'name' => 'Demonstration delivery']];

        $ingestor->ingest($environment, 'receipt-demo', $events);
        $ingestor->ingest($environment, 'receipt-demo', $events);
    }
}

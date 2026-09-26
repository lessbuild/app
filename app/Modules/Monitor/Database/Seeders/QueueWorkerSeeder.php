<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\QueueWorker;
use Illuminate\Database\Seeder;

class QueueWorkerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        QueueWorker::factory()->for(Monitor::factory()->queueMonitor()->paused()->state(['queue_token_hash' => null]))->create();
    }
}

<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\QueueSnapshot;
use Illuminate\Database\Seeder;

class QueueSnapshotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        QueueSnapshot::factory()->for(Monitor::factory()->queueMonitor()->paused()->state(['queue_token_hash' => null]))->create();
    }
}

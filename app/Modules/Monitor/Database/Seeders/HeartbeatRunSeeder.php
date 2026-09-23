<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\HeartbeatRun;
use Illuminate\Database\Seeder;

class HeartbeatRunSeeder extends Seeder
{
    public function run(): void
    {
        HeartbeatRun::factory()->create();
    }
}

<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\AlertDeliveryAttempt;
use Illuminate\Database\Seeder;

class AlertDeliveryAttemptSeeder extends Seeder
{
    public function run(): void
    {
        AlertDeliveryAttempt::factory()->create();
    }
}

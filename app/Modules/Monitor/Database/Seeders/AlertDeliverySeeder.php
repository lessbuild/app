<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\AlertDelivery;
use Illuminate\Database\Seeder;

class AlertDeliverySeeder extends Seeder
{
    public function run(): void
    {
        AlertDelivery::factory()->create();
    }
}

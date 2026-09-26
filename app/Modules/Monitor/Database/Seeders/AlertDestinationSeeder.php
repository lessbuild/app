<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\AlertDestination;
use Illuminate\Database\Seeder;

class AlertDestinationSeeder extends Seeder
{
    public function run(): void
    {
        AlertDestination::factory()->create();
    }
}

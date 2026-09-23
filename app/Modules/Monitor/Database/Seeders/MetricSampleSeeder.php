<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\MetricSample;
use Illuminate\Database\Seeder;

class MetricSampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MetricSample::factory()->create();
    }
}

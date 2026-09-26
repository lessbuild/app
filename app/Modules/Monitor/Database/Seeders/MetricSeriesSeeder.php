<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\MetricSeries;
use Illuminate\Database\Seeder;

class MetricSeriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MetricSeries::factory()->create();
    }
}

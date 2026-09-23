<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\Incident;
use Illuminate\Database\Seeder;

class IncidentSeeder extends Seeder
{
    public function run(): void
    {
        Incident::factory()->resolved()->create(['title' => 'Example recovered incident']);
    }
}

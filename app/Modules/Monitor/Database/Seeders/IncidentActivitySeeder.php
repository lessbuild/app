<?php

namespace App\Modules\Monitor\Database\Seeders;

use App\Modules\Monitor\Models\IncidentActivity;
use Illuminate\Database\Seeder;

class IncidentActivitySeeder extends Seeder
{
    public function run(): void
    {
        IncidentActivity::factory()->create(['action' => 'note', 'note' => 'Example incident investigation note.']);
    }
}

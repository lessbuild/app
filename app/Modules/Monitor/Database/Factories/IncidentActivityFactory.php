<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Models\IncidentActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IncidentActivity> */
class IncidentActivityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['incident_id' => Incident::factory(), 'action' => 'opened', 'note' => null, 'metadata' => []];
    }
}

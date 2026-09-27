<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IncidentActivity> */
class IncidentActivityFactory extends Factory
{
    protected $model = IncidentActivity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['incident_id' => Incident::factory(), 'action' => 'opened', 'note' => null, 'metadata' => []];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Environment> */
class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    /** An environment of a new project with Monitoring on (the project also gets its own Production). */
    public function definition(): array
    {
        $name = 'Staging '.Str::lower(Str::random(4));

        return [
            'project_id' => Project::factory()->withServices(['monitoring']),
            'name' => $name,
            'slug' => Str::slug($name),
            'kind' => EnvironmentKind::Staging,
        ];
    }
}

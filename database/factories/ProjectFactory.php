<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnvironmentKind;
use App\Models\Account;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = Str::title(fake()->word().' '.fake()->word());

        return [
            'account_id' => Account::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        ];
    }

    /** Like CreateProject: every project starts with a Production environment. */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $environment = new Environment;
            $environment->project()->associate($project);
            $environment->forceFill(['name' => 'Production', 'slug' => 'production', 'kind' => EnvironmentKind::Production])->save();
        });
    }

    /** @param list<string> $services */
    public function withServices(array $services): static
    {
        return $this->afterCreating(function (Project $project) use ($services): void {
            foreach ($services as $service) {
                $project->enabledServices()->forceCreate(['service' => $service]);
            }
        });
    }
}

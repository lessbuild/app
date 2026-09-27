<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProviderType;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Repository> */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    /**
     * A GitHub repository deploying to a live website, in a project of the website's account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'project_id' => fn (array $attributes): string => \App\Models\Project::factory()->create(['account_id' => Website::query()->whereKey($attributes['website_id'])->valueOrFail('account_id')])->id,
            'provider_id' => fn (array $attributes): int => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => Website::query()->whereKey($attributes['website_id'])->valueOrFail('account_id')])->id,
            'name' => 'shop',
            'url' => 'github.com/acme/shop',
            'branch' => 'main',
        ];
    }
}

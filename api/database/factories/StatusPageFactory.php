<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\StatusPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatusPage> */
class StatusPageFactory extends Factory
{
    protected $model = StatusPage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'created_by' => null,
            'name' => 'Acme status',
            'slug' => 'acme-'.Str::lower(Str::random(8)),
            'description' => null,
            'published' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(['published' => false]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\StatusSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatusSubscription> */
class StatusSubscriptionFactory extends Factory
{
    protected $model = StatusSubscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = Str::lower(Str::random(10)).'@example.com';

        return [
            'status_page_id' => StatusPage::factory(),
            'email' => $email,
            'email_hash' => StatusSubscription::hashEmail($email),
            'verification_token_hash' => null,
            'unsubscribe_token' => Str::random(64),
            'verified_at' => CarbonImmutable::now('UTC'),
        ];
    }

    public function pending(string $token = 'confirm-token'): static
    {
        return $this->state(['verified_at' => null, 'verification_token_hash' => hash('sha256', $token)]);
    }
}

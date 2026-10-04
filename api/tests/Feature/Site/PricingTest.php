<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that pricing gives yearly prices (two months free) and the trial.
     *
     * @return void
     */
    public function test_the_pricing_page_shows_yearly_prices_and_the_trial(): void
    {
        config(['billing.prices.deploy.tier' => ['pro' => 'price_pro_monthly'], 'billing.prices_yearly.deploy.tier' => ['pro' => 'price_pro_yearly'], 'billing.trial_days' => 14]);

        $this->getJson('/api/app/site/pricing')->assertOk()->assertJsonPath('trialDays', 14)
            ->assertJsonFragment(['key' => 'pro', 'monthlyCents' => 1900, 'yearlyCents' => 19000]);
    }
}

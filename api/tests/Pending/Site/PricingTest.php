<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pricing page offers yearly billing with two months free, and says the first paid plan starts with a trial.
     * (From the billing tests; the pricing page arrives with the public site.)
     */
    public function test_the_pricing_page_shows_yearly_prices_and_the_trial(): void
    {
        config(['billing.prices.deploy.tier' => ['pro' => 'price_pro_monthly'], 'billing.prices_yearly.deploy.tier' => ['pro' => 'price_pro_yearly'], 'billing.trial_days' => 14]);

        $this->get('/pricing')->assertSee('Your first paid plan is free for 14 days');
        $this->get('/pricing?billing=yearly')->assertOk()->assertSee('Yearly · 2 months free')->assertSee('$190')->assertSee('/ year');
    }
}

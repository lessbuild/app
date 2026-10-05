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

    /**
     * Check that the key features table follows the tiers' own limits and flags, that metered services say each tier's
     * allowance, and that the typical setups pick real tiers.
     *
     * @return void
     */
    public function test_the_pricing_page_has_key_features_usage_and_typical_setups(): void
    {
        $response = $this->getJson('/api/app/site/pricing')->assertOk();
        $services = collect((array) $response->json('services'))->keyBy('key');

        $deploy = collect((array) $services['deploy']['matrix'])->flatMap(fn (array $group): array => $group['rows'])->keyBy('label');
        $this->assertSame(['1', '2', '5', '20', '50', 'Unlimited'], $deploy['Servers']['values']);
        $this->assertSame([false, false, false, false, true, true], $deploy['Load balancers']['values']);
        $this->assertSame([500_000, 10_000_000, 50_000_000, 180_000_000], $services['monitoring']['meters'][0]['allowances']);
        $this->assertTrue(collect((array) $services['deploy']['tiers'])->firstWhere('key', 'pro')['recommended']);

        foreach ($response->json('presets') as $preset) {
            foreach ($preset['picks'] as $service => $tier) {
                $this->assertContains($tier, collect((array) $services[$service]['tiers'])->pluck('key')->all(), $preset['key'].' picks a real '.$service.' tier');
            }
        }
    }

    /**
     * Check that every comparison is listed, and that a comparison page carries its questions as FAQ structured data.
     *
     * @return void
     */
    public function test_the_comparisons_are_listed_with_their_questions(): void
    {
        $this->getJson('/api/app/site/compare')->assertOk()->assertJsonCount(count((array) config('compare.competitors')), 'comparisons')
            ->assertJsonFragment(['slug' => 'laravel-forge', 'what' => 'Server management']);

        $page = $this->getJson('/api/app/site/compare/ploi')->assertOk()->assertJsonCount(4, 'faqs')->assertJsonPath('overlaps.0.key', 'deploy');
        $this->assertContains('FAQPage', collect((array) $page->json('meta.structuredData.@graph'))->pluck('@type')->all());
        $this->assertStringContainsString('Deploy from $9 a month', (string) $page->json('startingPrices'));
    }
}

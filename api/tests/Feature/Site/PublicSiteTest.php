<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Models\Account;
use App\Models\User;
use App\Platform\Catalog\DeployCatalog;
use App\Platform\ServiceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check that guests get the home page's copy, with every service, and signed-in people are sent to their dashboard.
     *
     * @return void
     */
    public function test_guests_get_the_home_page_and_signed_in_people_their_dashboard(): void
    {
        $home = $this->getJson('/api/app/site/home')->assertOk()
            ->assertJsonPath('hero.headline', 'Ship with confidence.')->assertJsonPath('hero.accent', 'Know what happens next.')
            ->assertJsonPath('meta.canonical', route('home'));
        foreach (app(ServiceRegistry::class)->all() as $service) {
            $home->assertJsonFragment(['key' => $service->key(), 'name' => $service->name()]);
        }
        $this->getJson('/api/app/site')->assertOk()->assertJsonPath('signedIn', false)->assertJsonPath('contactEmail', config('legal.contact_email'));

        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();
        $this->actingAs($user)->getJson('/api/app/site/home')->assertJsonRedirect('/dashboard');
        $this->actingAs($user)->getJson('/api/app/site')->assertJsonPath('signedIn', true);
    }

    /**
     * Check that every service has a page with its own copy, and unknown services are a 404.
     *
     * @return void
     */
    public function test_each_service_has_a_page(): void
    {
        foreach (app(ServiceRegistry::class)->all() as $service) {
            $this->getJson("/api/app/site/features/{$service->key()}")->assertOk()->assertJsonPath('service.name', $service->name())
                ->assertJsonPath('copy.headline', config('marketing.services.'.$service->key().'.headline'));
        }
        $this->getJson('/api/app/site/features/nonsense')->assertNotFound();
        $this->getJson('/api/app/site/features/deploy')->assertJsonHasText('A stack per pull request', 'Do my old scripts keep working?', 'Keep production actions accountable.')
            ->assertJsonFragment(['key' => 'infrastructure']);
    }

    /**
     * Check that pricing comes from the billing catalogue.
     *
     * @return void
     */
    public function test_pricing_comes_from_the_catalogue(): void
    {
        $page = $this->getJson('/api/app/site/pricing')->assertOk();
        foreach (DeployCatalog::billing()->tiers as $tier) {
            $page->assertJsonFragment(['key' => $tier->key, 'name' => $tier->name, 'monthlyCents' => $tier->monthlyCents]);
        }
        $page->assertJsonFragment(['monthlyCents' => 1900])->assertJsonFragment(['monthlyCents' => 0])->assertJsonFragment(['key' => 'monitoring']);
    }

    /**
     * Check that the privacy policy and terms are public, link to each other and say where to write.
     *
     * @return void
     */
    public function test_the_privacy_policy_and_terms_are_public(): void
    {
        $this->getJson('/api/app/site/legal/privacy')->assertOk()->assertJsonPath('copy.title', 'Privacy policy')->assertJsonHasText('sets no cookies')
            ->assertJsonPath('contactEmail', config('legal.contact_email'))->assertJsonPath('other.page', 'terms');
        $this->getJson('/api/app/site/legal/terms')->assertOk()->assertJsonPath('copy.title', 'Terms of service')->assertJsonHasText('Acceptable use');
        $this->getJson('/api/app/site/legal/legal')->assertNotFound();
    }
}

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

    public function test_guests_get_the_home_page_and_signed_in_people_their_dashboard(): void
    {
        $home = $this->get('/')->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
        $home->assertSee('Ship with confidence.')->assertSee('Know what happens next.')->assertSee('Choose the tool for the work in front of you.')->assertSee('A clear view for every kind of work.')->assertSee('Better together')->assertSee('index, follow', false);
        foreach (app(ServiceRegistry::class)->all() as $service) {
            $home->assertSee($service->name())->assertSee(route('features', $service->key()));
        }

        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();
        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }

    public function test_each_service_has_a_page(): void
    {
        foreach (app(ServiceRegistry::class)->all() as $service) {
            $this->get("/features/{$service->key()}")->assertOk()->assertSee($service->name())->assertSee(config('marketing.services.'.$service->key().'.headline'));
        }
        $this->get('/features/nonsense')->assertNotFound();
        $this->get('/features/deploy')->assertSee('A stack per pull request')->assertSee('Do my old scripts keep working?')->assertSee('Keep production actions accountable.')->assertSee(route('features', 'infrastructure'));
    }

    public function test_pricing_comes_from_the_catalogue(): void
    {
        $page = $this->get('/pricing')->assertOk();
        foreach (DeployCatalog::billing()->tiers as $tier) {
            $page->assertSee($tier->name);
        }
        $page->assertSee('$19')->assertSee('Free')->assertSee('id="monitoring"', false);
    }

    public function test_the_privacy_policy_and_terms_are_public_and_linked_from_sign_up(): void
    {
        $this->get('/privacy')->assertOk()->assertHeader('Cache-Control', 'max-age=300, public')->assertSee('Privacy policy')->assertSee('sets no cookies')->assertSee(config('legal.contact_email'))->assertSee(route('legal', 'terms'));
        $this->get('/terms')->assertOk()->assertSee('Terms of service')->assertSee('Acceptable use');
        $this->get('/legal')->assertNotFound();
        $this->get('/register')->assertOk()->assertSee(route('legal', 'terms'))->assertSee(route('legal', 'privacy'));
        $this->get('/pricing')->assertSee(route('legal', 'privacy'));
    }
}

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
        $home->assertSee('Ship, run and understand your apps from one place.')->assertSee('Better together')->assertSee('index, follow', false);
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
        $this->get('/features/deploy')->assertSee('A stack per pull request')->assertSee('Do my old scripts keep working?');
    }

    public function test_pricing_comes_from_the_catalogue(): void
    {
        $page = $this->get('/pricing')->assertOk();
        foreach (DeployCatalog::billing()->tiers as $tier) {
            $page->assertSee($tier->name);
        }
        $page->assertSee('$19')->assertSee('Free')->assertSee('id="monitoring"', false);
    }
}

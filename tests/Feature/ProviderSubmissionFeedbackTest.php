<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSubmissionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_workspace_can_create_provider_without_paid_monitoring(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('providers.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Provider created successfully.');

        $provider = $user->workspaceProviders()->sole();
        $this->assertFalse($provider->connection_monitoring_enabled);
        $this->assertSame('digitalocean', $provider->provider);
        $this->withCookie(session()->getName(), session()->getId())->get(route('providers.show', $provider))
            ->assertOk()->assertSee('Provider created successfully.')->assertDontSee('fixture-private-token');
    }

    public function test_paid_monitoring_is_not_selected_for_a_free_workspace(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $response = $this->actingAs(User::factory()->create())->get(route('providers.create'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $checkbox = (new \DOMXPath($document))->query('//input[@id="connection_monitoring_enabled"]')->item(0);
        $this->assertFalse($checkbox->hasAttribute('checked'));
        $this->assertTrue($checkbox->hasAttribute('disabled'));
        $response->assertSee('You can add a provider and test its connection manually.');
    }

    public function test_plan_rejection_is_visible_after_redirect_without_flashing_the_token(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $this->actingAs(User::factory()->create())
            ->from(route('providers.create'))
            ->post(route('providers.store'), $this->payload() + ['connection_monitoring_enabled' => '1'])
            ->assertRedirect(route('providers.create'))
            ->assertSessionHasErrors('plan')
            ->assertSessionMissing('_old_input.token');

        $this->withCookie(session()->getName(), session()->getId())->get(route('providers.create'))->assertOk()
            ->assertSee('Provider could not be saved.')
            ->assertSee('Your current plan does not include monitoring.')
            ->assertDontSee('fixture-private-token');
        $this->assertDatabaseCount('providers', 0);
    }

    public function test_missing_fields_show_a_validation_summary(): void
    {
        $this->actingAs(User::factory()->create())->from(route('providers.create'))
            ->post(route('providers.store'), array_replace($this->payload(), ['name' => '', 'connection_monitoring_enabled' => '0']))
            ->assertSessionHasErrors('name');

        $this->withCookie(session()->getName(), session()->getId())->get(route('providers.create'))->assertOk()
            ->assertSee('Provider could not be saved.')
            ->assertSee('The name field is required.');
    }

    public function test_entitled_workspace_keeps_monitoring_enabled_by_default(): void
    {
        config(['billing.enforce_entitlements' => true, 'billing.plans.free.entitlements' => ['monitoring']]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('providers.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertTrue($user->workspaceProviders()->sole()->connection_monitoring_enabled);
    }

    /** @return array<string, string> Non-production provider fields for isolated submission tests. */
    private function payload(): array
    {
        return ['provider' => 'digitalocean', 'name' => 'Test connection', 'description' => 'Test', 'token' => 'fixture-private-token'];
    }
}

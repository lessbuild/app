<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_provider_credentials_are_checked_against_fixed_https_endpoints(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/user' => Http::response(['login' => 'owner']),
            'https://gitlab.com/api/v4/user' => Http::response(['username' => 'owner']),
            'https://api.bitbucket.org/2.0/user' => Http::response(['display_name' => 'Owner']),
            'https://api.digitalocean.com/v2/account' => Http::response(['account' => ['status' => 'active']]),
        ]);
        $owner = User::factory()->create();
        $providers = [
            Provider::TYPE_GITHUB => 'https://api.github.com/user',
            Provider::TYPE_GITLAB => 'https://gitlab.com/api/v4/user',
            Provider::TYPE_BITBUCKET => 'https://api.bitbucket.org/2.0/user',
            Provider::TYPE_DIGITALOCEAN => 'https://api.digitalocean.com/v2/account',
        ];

        foreach ($providers as $type => $url) {
            $provider = $this->provider($owner, $type, "{$type}-secret");

            $this->actingAs($owner)
                ->from(route('providers.show', $provider))
                ->post(route('providers.connection.test', $provider))
                ->assertRedirect(route('providers.show', $provider))
                ->assertSessionHas('provider_connection.successful', true);

            Http::assertSent(function (Request $request) use ($type, $url): bool {
                $authorization = $request->header('Authorization')[0] ?? null;
                $privateToken = $request->header('PRIVATE-TOKEN')[0] ?? null;

                return $request->method() === 'GET'
                    && $request->url() === $url
                    && ($type === Provider::TYPE_GITLAB
                        ? $privateToken === 'gitlab-secret' && $authorization === null
                        : $authorization === "Bearer {$type}-secret");
            });
        }

        Http::assertSentCount(4);
    }

    public function test_success_and_failure_feedback_never_exposes_credentials_or_response_bodies(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/user' => Http::sequence()
                ->push(['login' => 'owner'], 200)
                ->push(['message' => 'upstream-body-never-render'], 401),
        ]);
        $owner = User::factory()->create();
        $provider = $this->provider($owner, Provider::TYPE_GITHUB, 'credential-never-render');

        $this->actingAs($owner)
            ->post(route('providers.connection.test', $provider))
            ->assertSessionHas('provider_connection', [
                'successful' => true,
                'message' => 'Connection successful. GitHub accepted this credential.',
            ]);

        $response = $this->actingAs($owner)
            ->post(route('providers.connection.test', $provider));
        $response->assertSessionHas('provider_connection', [
            'successful' => false,
            'message' => 'Connection failed. GitHub returned HTTP 401. Verify the credential and its permissions.',
        ]);

        $page = $this->actingAs($owner)
            ->withSession(['provider_connection' => $response->getSession()->get('provider_connection')])
            ->get(route('providers.show', $provider));
        $page
            ->assertSuccessful()
            ->assertSee('Connection failed. GitHub returned HTTP 401.')
            ->assertDontSee('credential-never-render')
            ->assertDontSee('upstream-body-never-render');
    }

    public function test_network_failures_return_safe_actionable_feedback(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('network detail never render'));
        $owner = User::factory()->create();
        $provider = $this->provider($owner, Provider::TYPE_DIGITALOCEAN, 'cloud-secret');

        $this->actingAs($owner)
            ->post(route('providers.connection.test', $provider))
            ->assertSessionHas('provider_connection', [
                'successful' => false,
                'message' => 'Could not reach DigitalOcean. Try again later.',
            ]);
    }

    public function test_other_users_and_guests_cannot_test_a_provider_credential(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $provider = $this->provider($owner, Provider::TYPE_GITHUB, 'private-secret');

        $this->actingAs($intruder)
            ->post(route('providers.connection.test', $provider))
            ->assertForbidden();
        $this->app['auth']->logout();
        $this->post(route('providers.connection.test', $provider))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_provider_page_exposes_a_csrf_protected_connection_action(): void
    {
        $owner = User::factory()->create();
        $provider = $this->provider($owner, Provider::TYPE_GITHUB, 'private-secret');

        $this->actingAs($owner)->get(route('providers.show', $provider))
            ->assertSuccessful()
            ->assertSee(route('providers.connection.test', $provider))
            ->assertSee('Test connection')
            ->assertSee('name="_token"', false);
    }

    public function test_connection_checks_are_rate_limited(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/user' => Http::response(['login' => 'owner']),
        ]);
        $owner = User::factory()->create();
        $provider = $this->provider($owner, Provider::TYPE_GITHUB, 'private-secret');

        foreach (range(1, 6) as $attempt) {
            $this->actingAs($owner)
                ->post(route('providers.connection.test', $provider))
                ->assertRedirect();
        }

        $this->actingAs($owner)
            ->post(route('providers.connection.test', $provider))
            ->assertTooManyRequests();
        Http::assertSentCount(6);
    }

    private function provider(User $user, string $type, string $token): Provider
    {
        return $user->providers()->create([
            'name' => str($type)->headline(),
            'provider' => $type,
            'token' => $token,
            'description' => 'Provider connection',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Data\EnterpriseSsoCallbackData;
use App\Models\Organization;
use App\Models\User;
use App\Services\EnterpriseOidc;
use App\Support\PublicDnsResolver;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class EnterpriseSsoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_entitled_callback_verifies_the_identity_and_marks_the_workspace_session(): void
    {
        $user = User::factory()->create();
        $organization = $user->currentOrganization;

        $this->mock(EnterpriseOidc::class, function (MockInterface $mock) use ($organization): void {
            $mock->shouldReceive('verify')->once()->andReturnUsing(
                function (EnterpriseSsoCallbackData $data, Organization $actual, User $actor, Session $session) use ($organization): void {
                    $this->assertSame('authorization-code', $data->code);
                    $this->assertSame('callback-state', $data->state);
                    $this->assertTrue($actual->is($organization));
                    $this->assertTrue($actor->is($organization->owner));
                    $session->put('organization_sso_verified.'.$actual->id, 1700000000);
                }
            );
        });

        $this->actingAs($user)->get(route('organizations.sso.callback', [
            'code' => 'authorization-code',
            'state' => 'callback-state',
        ]))->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Workspace SSO verified.');

        $this->assertSame(1700000000, session('organization_sso_verified.'.$organization->id));
    }

    public function test_entitlement_denial_precedes_callback_validation_and_remote_verification(): void
    {
        config(['billing.enforce_entitlements' => true]);
        $user = User::factory()->create();

        $this->mock(EnterpriseOidc::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->never();
        });

        $this->actingAs($user)->get(route('organizations.sso.callback'))->assertSessionHasErrors('plan');
        $this->assertNull(session('organization_sso_verified.'.$user->current_organization_id));
    }

    public function test_provider_failure_is_reported_as_the_existing_sanitized_sso_error(): void
    {
        $user = User::factory()->create();

        $this->mock(EnterpriseOidc::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andThrow(new RuntimeException('private token response'));
        });

        $this->actingAs($user)->get(route('organizations.sso.callback', [
            'code' => 'authorization-code',
            'state' => 'callback-state',
        ]))->assertSessionHasErrors('sso')
            ->assertSessionMissing('organization_sso_verified.'.$user->current_organization_id);
    }

    public function test_connect_delegates_authorization_url_generation_with_the_current_workspace(): void
    {
        $user = User::factory()->create();
        $organization = $user->currentOrganization;

        $this->mock(EnterpriseOidc::class, function (MockInterface $mock) use ($organization): void {
            $mock->shouldReceive('authorizationUrl')->once()->andReturnUsing(
                function (Session $session, Organization $actual) use ($organization): string {
                    $this->assertTrue($actual->is($organization));
                    $session->put('oidc.'.$actual->id, ['state' => 'hashed-state', 'verifier' => 'verifier']);

                    return 'https://idp.example/authorize';
                }
            );
        });

        $this->actingAs($user)->get(route('organizations.sso.connect'))
            ->assertRedirect('https://idp.example/authorize')
            ->assertSessionHas('oidc.'.$organization->id.'.verifier', 'verifier');
    }

    public function test_oidc_adapter_consumes_state_exchanges_pkce_code_validates_identity_and_marks_session(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $organization = $user->currentOrganization;
        $organization->forceFill([
            'sso_configuration' => [
                'issuer' => 'https://idp.example.test',
                'client_id' => 'buildpusher',
                'client_secret' => 'client-secret',
            ],
            'allowed_email_domains' => ['example.com'],
        ])->save();
        $session = app('session.store');
        $session->put('oidc.'.$organization->id, [
            'state' => hash('sha256', 'callback-state'),
            'verifier' => 'pkce-verifier',
        ]);

        $this->mock(PublicDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addresses')->times(4)->with('idp.example.test')->andReturn(['8.8.8.8']);
        });
        Http::preventStrayRequests();
        Http::fake([
            'https://idp.example.test/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://idp.example.test/authorize',
                'token_endpoint' => 'https://idp.example.test/token',
                'userinfo_endpoint' => 'https://idp.example.test/userinfo',
            ]),
            'https://idp.example.test/token' => Http::response(['access_token' => 'access-token']),
            'https://idp.example.test/userinfo' => Http::response([
                'email' => 'OWNER@example.com',
                'email_verified' => true,
            ]),
        ]);

        app(EnterpriseOidc::class)->verify(
            new EnterpriseSsoCallbackData('authorization-code', 'callback-state'),
            $organization,
            $user,
            $session,
        );

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token'
            && $request->data()['code'] === 'authorization-code'
            && $request->data()['code_verifier'] === 'pkce-verifier');
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://idp.example.test/userinfo'
            && $request->header('Authorization') === ['Bearer access-token']);
        $this->assertIsInt(session('organization_sso_verified.'.$organization->id));
        $this->assertNull(session('oidc.'.$organization->id));
    }
}

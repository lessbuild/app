<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ScimUser;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ScimProvisioningTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * The account's SCIM token.
     *
     * @var string
     */
    private string $token;

    /**
     * The project whose account provisions people.
     *
     * @var Project
     */
    private Project $project;

    /**
     * Turn SCIM on through the Security page, with new people joining as viewers.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequirePassword::class);
        $this->project = Project::factory()->create();
        $this->onTier($this->project, 'deploy', 'team');
        $owner = $this->ownerOf($this->project);
        $owner->forceFill(['current_account_id' => $this->project->account_id])->save();
        $this->actingAs($owner)->putJson('/api/app/account/security/scim', ['change' => 'role', 'scim_default_role' => 'viewer'])->assertOk();
        $this->token = (string) $this->actingAs($owner)->putJson('/api/app/account/security/scim', ['change' => 'token'])->assertOk()->json('token');
        $this->assertNotSame('', $this->token);
        $this->actingAs($owner)->getJson('/api/app/account/security')->assertOk()->assertJsonPath('scim.on', true)->assertJsonPath('scim.baseUrl', url('/api/scim/v2'));
    }

    /**
     * Check Okta-style provisioning: create, find by userName, deactivate with PATCH, reactivate, and delete, with
     * the membership following along; Entra ID's "False" strings work too.
     *
     * @return void
     */
    public function test_people_are_provisioned_and_deprovisioned(): void
    {
        $this->scim('get', '/Users?filter='.urlencode('userName eq "ada@example.com"'))->assertOk()->assertJsonPath('totalResults', 0);
        $created = $this->scim('post', '/Users', [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => 'Ada@Example.com', 'externalId' => 'okta-1',
            'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'], 'emails' => [['value' => 'ada@example.com', 'primary' => true]], 'active' => true,
        ])->assertCreated()->assertHeader('Content-Type', 'application/scim+json')->assertJsonPath('userName', 'ada@example.com')->assertJsonPath('active', true);
        $id = (string) $created->json('id');
        $ada = User::query()->where('email', 'ada@example.com')->sole();
        $this->assertSame('Ada Lovelace', $ada->name);
        $this->assertSame(AccountRole::Viewer, $this->membership($ada)?->role);
        $this->scim('post', '/Users', ['userName' => 'ada@example.com'])->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');

        $this->scim('get', '/Users?filter='.urlencode('userName eq "ada@example.com"'))->assertOk()->assertJsonPath('Resources.0.id', $id);
        $this->scim('get', '/Users?filter='.urlencode('externalId eq "okta-1"'))->assertJsonPath('totalResults', 1);

        $this->scim('patch', "/Users/{$id}", ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]])
            ->assertOk()->assertJsonPath('active', false);
        $this->assertNull($this->membership($ada));
        $this->scim('patch', "/Users/{$id}", ['Operations' => [['op' => 'replace', 'value' => ['active' => true]]]])->assertOk()->assertJsonPath('active', true);
        $this->assertNotNull($this->membership($ada));

        $this->scim('delete', "/Users/{$id}")->assertNoContent();
        $this->assertNull($this->membership($ada));
        $this->assertSame(0, ScimUser::query()->count());
        $this->scim('get', "/Users/{$id}")->assertNotFound();
    }

    /**
     * Check a bad token, an unsupported filter, the account's email domain rule and the last owner are refused.
     *
     * @return void
     */
    public function test_requests_are_checked(): void
    {
        $this->withToken('nope')->getJson('/api/scim/v2/Users')->assertUnauthorized();
        $this->scim('get', '/Users?filter='.urlencode('name.givenName sw "A"'))->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
        $this->scim('get', '/ServiceProviderConfig')->assertOk()->assertJsonPath('patch.supported', true);
        $this->scim('get', '/Groups')->assertOk()->assertJsonPath('totalResults', 0);

        $owner = $this->project->account->memberships()->where('role', AccountRole::Owner)->sole()->user;
        $owned = $this->scim('post', '/Users', ['userName' => $owner->email])->assertCreated();
        $this->scim('delete', '/Users/'.$owned->json('id'))->assertStatus(409);
        $this->assertNotNull($this->membership($owner));

        $this->project->account->forceFill(['allowed_email_domains' => ['acme.test']])->save();
        $this->scim('post', '/Users', ['userName' => 'eve@elsewhere.test'])->assertStatus(400);
    }

    /**
     * Send a SCIM request with the token.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function scim(string $method, string $path, array $body = []): TestResponse
    {
        return $this->withToken($this->token)->json(strtoupper($method), '/api/scim/v2'.$path, $body, ['Content-Type' => 'application/scim+json']);
    }

    /**
     * Find the person's membership of the account.
     *
     * @param  User  $user
     * @return Membership|null
     */
    private function membership(User $user): ?Membership
    {
        return Membership::query()->where('account_id', $this->project->account_id)->where('user_id', $user->id)->first();
    }
}

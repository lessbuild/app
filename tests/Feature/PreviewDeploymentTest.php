<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Jobs\Web\AddWebsiteJob;
use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Build;
use App\Models\PreviewDeployment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\RepositoryDeploymentPlan;
use App\Services\WebsiteProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PreviewDeploymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enforce_entitlements' => false]);
    }

    public function test_pull_request_provisions_updates_and_closes_an_isolated_preview(): void
    {
        config(['billing.enforce_limits' => false]);
        Queue::fake();
        [$owner, $source, $project] = $this->application();
        $secret = 'preview-webhook-'.str_repeat('x', 48);
        $source->update(['webhook_enabled' => true, 'webhook_secret' => $secret]);
        $revision = str_repeat('a', 40);
        $payload = $this->payload('opened', $revision);

        $this->send($source, $payload, $secret, 'preview-open')
            ->assertAccepted()
            ->assertJson(['status' => PreviewDeployment::STATUS_PROVISIONING]);

        $preview = PreviewDeployment::query()->sole();
        $this->assertSame('pr-17-storefront.previews.example.com', $preview->url);
        $this->assertSame('preview', $preview->environment->type);
        $this->assertSame('feature/checkout', $preview->repository->branch);
        $this->assertNotSame($source->website_id, $preview->website_id);
        Queue::assertPushed(AddWebsiteJob::class, fn (AddWebsiteJob $job): bool => $job->website->is($preview->website));

        $preview->website->update(['provisioning_status' => Website::STATUS_PROVISIONING]);
        $this->post(URL::signedRoute('callbacks.website', [
            'website' => $preview->website,
            'attempt' => $preview->website->provisioning_token,
        ]), ['status' => app(WebsiteProvisioningPlan::class)->finalStage()])->assertOk();

        $build = $preview->repository->builds()->sole();
        $this->assertSame($revision, $build->revision);
        Queue::assertPushed(PublishRepositoryJob::class, fn (PublishRepositoryJob $job): bool => $job->build->is($build));

        $build->update(['status' => Build::STATUS_RUNNING]);
        $this->post(URL::signedRoute('callbacks.build.status', $build), [
            'status' => app(RepositoryDeploymentPlan::class)->finalStage(),
        ])->assertNoContent();
        $this->assertSame(PreviewDeployment::STATUS_READY, $preview->fresh()->status);

        $closed = $this->payload('closed', $revision);
        $this->send($source, $closed, $secret, 'preview-close')
            ->assertOk()
            ->assertJson(['status' => PreviewDeployment::STATUS_CLOSED]);
        $this->assertNotNull($preview->fresh()->closed_at);
        $this->assertTrue($preview->website->fresh()->trashed());
        Queue::assertPushed(DeleteWebsiteFromCaddyJob::class);
    }

    public function test_preview_settings_are_workspace_scoped_and_validated(): void
    {
        [$owner, , $project] = $this->application(previews: false);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->patch(route('projects.previews.update', $project), [
            'preview_enabled' => '1', 'preview_domain' => 'outside.example.com', 'preview_ttl_hours' => 24,
        ])->assertForbidden();
        $this->actingAs($owner)->patch(route('projects.previews.update', $project), [
            'preview_enabled' => '1', 'preview_domain' => 'https://previews.example.com/', 'preview_ttl_hours' => 48,
        ])->assertRedirect();

        $project->refresh();
        $this->assertTrue($project->preview_enabled);
        $this->assertSame('previews.example.com', $project->preview_domain);
        $this->assertSame(48, $project->preview_ttl_hours);
    }

    /** @return array{User, Repository, Project} */
    private function application(bool $previews = true): array
    {
        $owner = User::factory()->create();
        $provider = $owner->providers()->create([
            'name' => 'GitHub', 'provider' => Provider::TYPE_GITHUB, 'token' => 'token', 'description' => 'Source',
        ]);
        $server = $owner->servers()->create(['name' => 'Production', 'provisioning_status' => Server::STATUS_ACTIVE]);
        $website = $owner->websites()->create([
            'server_id' => $server->id, 'name' => 'Storefront', 'description' => 'Website',
            'environment' => 'APP_ENV=production', 'url' => 'store.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);
        $repository = $owner->repositories()->create([
            'provider_id' => $provider->id, 'website_id' => $website->id, 'name' => 'Storefront source',
            'url' => 'github.com/example/storefront.git', 'branch' => 'main', 'description' => 'Source',
        ]);
        $project = $owner->currentOrganization->projects()->create([
            'created_by' => $owner->id, 'name' => 'Storefront', 'slug' => 'storefront',
            'preview_enabled' => $previews, 'preview_domain' => $previews ? 'previews.example.com' : null,
            'preview_ttl_hours' => 72,
        ]);
        $project->environments()->create([
            'name' => 'Production', 'slug' => 'production', 'type' => 'production', 'branch' => 'main',
            'server_id' => $server->id, 'website_id' => $website->id, 'is_protected' => true,
        ]);

        return [$owner, $repository, $project];
    }

    private function payload(string $action, string $revision): array
    {
        return [
            'action' => $action,
            'number' => 17,
            'pull_request' => [
                'title' => 'Preview checkout',
                'head' => ['ref' => 'feature/checkout', 'sha' => $revision],
            ],
        ];
    }

    private function send(Repository $repository, array $payload, string $secret, string $delivery): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('webhooks.repositories.receive', $repository), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
        ], content: $raw);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\CloneRepositoryScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceProviderDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_gitlab_and_bitbucket_are_available_source_provider_types(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('providers.create'))
            ->assertSuccessful()
            ->assertSee('GitHub')
            ->assertSee('GitLab')
            ->assertSee('Bitbucket');

        foreach ([Provider::TYPE_GITLAB, Provider::TYPE_BITBUCKET] as $type) {
            $this->actingAs($user)->post(route('providers.store'), [
                'provider' => $type,
                'name' => ucfirst($type),
                'description' => 'Source control provider',
                'token' => $type.'-secret',
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('providers', ['provider' => Provider::TYPE_GITLAB]);
        $this->assertDatabaseHas('providers', ['provider' => Provider::TYPE_BITBUCKET]);
    }

    public function test_repository_urls_are_normalized_and_must_match_the_selected_provider(): void
    {
        [$user, $website, $gitlab, $bitbucket] = $this->infrastructure();

        $this->actingAs($user)->post(route('repositories.store'), [
            ...$this->payload($website, $gitlab),
            'name' => 'GitLab application',
            'url' => 'git@gitlab.com:Example/Platform/Application.git',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('repositories.store'), [
            ...$this->payload($website, $bitbucket),
            'name' => 'Bitbucket application',
            'url' => 'https://BITBUCKET.org/Workspace/Application/',
        ])->assertRedirect();

        $this->assertDatabaseHas('repositories', [
            'provider_id' => $gitlab->id,
            'url' => 'gitlab.com/Example/Platform/Application.git',
        ]);
        $this->assertDatabaseHas('repositories', [
            'provider_id' => $bitbucket->id,
            'url' => 'bitbucket.org/Workspace/Application.git',
        ]);

        $this->actingAs($user)->post(route('repositories.store'), [
            ...$this->payload($website, $gitlab),
            'url' => 'github.com/example/wrong-host.git',
        ])->assertSessionHasErrors('url');

        foreach ([
            [$gitlab, 'gitlab.com/example/../secret.git'],
            [$bitbucket, 'bitbucket.org/example/project/extra.git'],
            [$bitbucket, 'bitbucket.org@example.com/workspace/project.git'],
            [$bitbucket, 'bitbucket.org/workspace/project.git?ref=main'],
        ] as [$provider, $url]) {
            $this->actingAs($user)->post(route('repositories.store'), [
                ...$this->payload($website, $provider),
                'url' => $url,
            ])->assertSessionHasErrors('url');
        }

        $this->assertDatabaseCount('repositories', 2);
    }

    public function test_each_source_provider_generates_safe_host_specific_credentials(): void
    {
        [, $website, $gitlab, $bitbucket] = $this->infrastructure();

        foreach ([
            [$gitlab, 'gitlab.com/example/application.git', 'oauth2'],
            [$bitbucket, 'bitbucket.org/example/application.git', 'x-token-auth'],
        ] as [$provider, $url, $username]) {
            $repository = $provider->user->repositories()->create([
                ...$this->payload($website, $provider),
                'url' => $url,
            ]);
            $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);
            $script = (new CloneRepositoryScript)->script(1, $build);
            $credentials = "machine {$provider->repositoryHost()}\nlogin {$username}\npassword {$provider->token}\n";

            $this->assertTrue($repository->isDeploymentReady());
            $this->assertStringContainsString(base64_encode($credentials), $script);
            $this->assertStringNotContainsString($provider->token, $script);
            $this->assertStringContainsString("https://{$url}", $script);
        }
    }

    public function test_repository_form_lists_every_source_provider_but_not_cloud_providers(): void
    {
        [$user, , $gitlab, $bitbucket, $digitalOcean] = $this->infrastructure();

        $this->actingAs($user)->get(route('repositories.create'))
            ->assertSuccessful()
            ->assertSee($gitlab->name)
            ->assertSee($bitbucket->name)
            ->assertDontSee($digitalOcean->name);
    }

    private function infrastructure(): array
    {
        $user = User::factory()->create();
        $gitlab = $user->providers()->create([
            'name' => 'GitLab Source',
            'provider' => Provider::TYPE_GITLAB,
            'token' => 'gitlab-secret',
            'description' => 'GitLab source control',
        ]);
        $bitbucket = $user->providers()->create([
            'name' => 'Bitbucket Source',
            'provider' => Provider::TYPE_BITBUCKET,
            'token' => 'bitbucket-secret',
            'description' => 'Bitbucket source control',
        ]);
        $digitalOcean = $user->providers()->create([
            'name' => 'DigitalOcean Cloud',
            'provider' => Provider::TYPE_DIGITALOCEAN,
            'token' => 'cloud-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'provider_id' => $digitalOcean->id,
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Website',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$user, $website, $gitlab, $bitbucket, $digitalOcean];
    }

    private function payload(Website $website, Provider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => $provider->repositoryHost().'/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ];
    }
}

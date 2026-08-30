<?php

namespace Tests\Feature;

use App\Jobs\Repository\PublishRepositoryJob;
use App\Models\Build;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Repository\CheckoutRepositoryScript;
use App\Scripts\Repository\CloneRepositoryScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositorySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_urls_are_normalized_and_branches_are_configurable(): void
    {
        [$user, $provider, , $website] = $this->infrastructure();

        $this->actingAs($user)->post(route('repositories.store'), [
            ...$this->payload($provider, $website),
            'url' => 'git@github.com:Example/Project.git',
            'branch' => 'release/2026.08',
        ])->assertRedirect();

        $repository = Repository::query()->sole();
        $this->assertSame('github.com/Example/Project.git', $repository->url);
        $this->assertSame('release/2026.08', $repository->branch);
    }

    public function test_unsafe_repository_urls_and_branch_names_are_rejected(): void
    {
        [$user, $provider, , $website] = $this->infrastructure();

        foreach ([
            'gitlab.com/example/project.git',
            'github.com/example/project.git; touch /tmp/pwned',
            'github.com/example/project/extra.git',
        ] as $url) {
            $this->actingAs($user)->post(route('repositories.store'), [
                ...$this->payload($provider, $website),
                'url' => $url,
            ])->assertSessionHasErrors(['url']);
        }

        foreach (['-force', 'release..next', 'feature name', 'main; id'] as $branch) {
            $this->actingAs($user)->post(route('repositories.store'), [
                ...$this->payload($provider, $website),
                'branch' => $branch,
            ])->assertSessionHasErrors(['branch']);
        }

        $this->assertDatabaseCount('repositories', 0);
    }

    public function test_repository_forms_only_offer_active_websites_and_preserve_selection(): void
    {
        [$user, $provider, $server, $activeWebsite] = $this->infrastructure();
        $inactiveWebsite = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Inactive Website',
            'description' => 'Pending',
            'environment' => 'APP_ENV=production',
            'url' => 'pending.example.com',
            'provisioning_status' => Website::STATUS_PROVISIONING,
        ]);
        $repository = $user->repositories()->create($this->payload($provider, $activeWebsite));

        $this->actingAs($user)->get(route('repositories.create'))
            ->assertSuccessful()
            ->assertSee('active website')
            ->assertDontSee($inactiveWebsite->name);

        $this->actingAs($user)->get(route('repositories.edit', $repository))
            ->assertSuccessful()
            ->assertSeeInOrder(['value="'.$activeWebsite->id.'"', 'selected'], false)
            ->assertDontSee($inactiveWebsite->name);
    }

    public function test_deployment_requires_an_active_website_and_server(): void
    {
        Queue::fake();
        [$user, $provider, $server, $website] = $this->infrastructure();
        $repository = $user->repositories()->create($this->payload($provider, $website));

        $server->update(['provisioning_status' => Server::STATUS_FAILED]);
        $this->actingAs($user)->post(route('repositories.deploy', $repository))
            ->assertSessionHas('error', 'The website and server must be active before deployment.');

        $this->assertDatabaseCount('builds', 0);
        Queue::assertNotPushed(PublishRepositoryJob::class);
    }

    public function test_clone_and_checkout_scripts_quote_inputs_and_remove_credentials(): void
    {
        [, $provider, , $website] = $this->infrastructure();
        $provider->update(['token' => "token'; touch /tmp/token-pwned"]);
        $repository = $provider->user->repositories()->create([
            ...$this->payload($provider, $website),
            'branch' => 'release/2026.08',
        ]);
        $build = $repository->builds()->create(['status' => Build::STATUS_RUNNING]);

        $cloneScript = (new CloneRepositoryScript)->script(1, $build);
        $checkoutScript = (new CheckoutRepositoryScript)->script(2, $build);

        $this->assertStringNotContainsString($provider->fresh()->token, $cloneScript);
        $this->assertStringContainsString('base64 --decode', $cloneScript);
        $this->assertStringContainsString('.netrc', $cloneScript);
        $this->assertStringContainsString('rm -f -- "$0"', $cloneScript);
        $this->assertStringContainsString('curl --fail --silent --show-error --retry 2', $cloneScript);
        $this->assertStringNotContainsString('--insecure', $cloneScript);
        $this->assertStringContainsString("git -C '/var/www/active-website/setup' checkout --force 'release/2026.08'", $checkoutScript);

        $revision = str_repeat('a', 40);
        $build->update(['revision' => $revision]);
        $revisionScript = (new CheckoutRepositoryScript)->script(2, $build->fresh());
        $this->assertStringContainsString("rev-parse --verify '{$revision}'^{commit}", $revisionScript);
        $this->assertStringContainsString("merge-base --is-ancestor '{$revision}' 'origin/release/2026.08'", $revisionScript);
        $this->assertStringContainsString("checkout --detach --force '{$revision}'", $revisionScript);
        $this->assertStringNotContainsString('checkout --force \'release/2026.08\'', $revisionScript);
        $this->assertShellSyntax($revisionScript);

        foreach ([$cloneScript, $checkoutScript] as $script) {
            $syntaxCheck = new Process(['bash', '-n']);
            $syntaxCheck->setInput($script);
            $syntaxCheck->run();
            $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
        }
    }

    private function assertShellSyntax(string $script): void
    {
        $syntaxCheck = new Process(['bash', '-n']);
        $syntaxCheck->setInput($script);
        $syntaxCheck->run();
        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }

    private function infrastructure(): array
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'GitHub',
            'provider' => Provider::TYPE_GITHUB,
            'token' => 'github-secret',
            'description' => 'Source provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Production',
            'provisioning_status' => Server::STATUS_ACTIVE,
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Active Website',
            'description' => 'Active website',
            'environment' => 'APP_ENV=production',
            'url' => 'active.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$user, $provider, $server, $website];
    }

    private function payload(Provider $provider, Website $website): array
    {
        return [
            'provider_id' => $provider->id,
            'website_id' => $website->id,
            'name' => 'Application repository',
            'url' => 'github.com/example/application.git',
            'branch' => 'main',
            'description' => 'Application source',
        ];
    }
}

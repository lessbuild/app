<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateApiToken;
use App\Data\ApiTokens\CreateApiTokenData;
use App\Enums\ApiScope;
use App\Enums\ProviderType;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use BuildPusher\Sdk\ApiException;
use BuildPusher\Sdk\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class PhpSdkTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check the PHP SDK against the real API routes (its transport is the test client): who the token is, projects,
     * deploying a ref, reading the deploy and its log, variables, Analytics sites and reports, and errors as exceptions.
     *
     * @return void
     */
    public function test_the_php_sdk_drives_the_api(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['deploy', 'infrastructure', 'analytics'])->create(['name' => 'Shop']);
        $owner = $this->ownerOf($project);
        $production = $project->environments()->where('slug', 'production')->firstOrFail();
        $website = Website::factory()->create(['server_id' => Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $project->account_id])->id])->id]);
        Repository::factory()->create(['website_id' => $website->id, 'project_id' => $project->id, 'provider_id' => Provider::factory()->type(ProviderType::GitHub)->create(['account_id' => $project->account_id])->id, 'environment_id' => $production->id]);
        $token = app(CreateApiToken::class)->handle($owner, $project->account, new CreateApiTokenData('sdk', [ApiScope::DeployRead, ApiScope::from('deploy:write'), ApiScope::AnalyticsRead], 30))->plainText;

        $client = new Client($token, (string) config('app.url'), function (string $method, string $url, array $headers, ?string $body): array {
            $this->app['auth']->forgetGuards();
            $server = [];
            foreach ($headers as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
            $server['CONTENT_TYPE'] = $headers['Content-Type'];
            $response = $this->call($method, $url, [], [], [], $server, $body);

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
        });

        $this->assertSame($owner->email, $client->me()['email']);
        $this->assertSame(['Shop'], array_column($client->projects(), 'name'));

        $queued = $client->deploy($production->id, 'v1.4.0');
        $this->assertSame(['v1.4.0', Build::STATUS_RUNNING], [$queued['ref'], $client->deployment((int) $queued['id'])['status']]);
        $this->assertSame((int) $queued['id'], $client->log((int) $queued['id'])['deployment_id']);
        $this->assertSame([(int) $queued['id']], array_map(fn (array $build): int => (int) $build['id'], $client->deployments()));

        $this->assertSame('applied', $client->replaceVariables($production->id, "APP_ENV=production\n")['status']);

        $site = AnalyticsSite::factory()->for($project)->create(['name' => 'Shop site']);
        $this->assertSame(['Shop site'], array_column($client->analyticsSites(), 'name'));
        $this->assertSame(['days' => 7, 'timezone' => $site->timezone], array_intersect_key($client->analyticsReport($site->id, 7, ['device' => 'Mobile'])['period'], ['days' => 1, 'timezone' => 1]));

        try {
            $client->deployment(999999);
            $this->fail('A missing deploy should throw.');
        } catch (ApiException $exception) {
            $this->assertSame(404, $exception->status);
        }
    }
}

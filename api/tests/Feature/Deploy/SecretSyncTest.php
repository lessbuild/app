<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use App\Actions\Deploy\SyncSecrets;
use App\Contracts\Monitoring\DnsResolver;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\SecretSync;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class SecretSyncTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check Doppler, 1Password Connect and AWS Secrets Manager sync into secret variables they manage: values added
     * and updated, keys gone from the source removed, hand-set variables and invalid names left alone, credentials
     * encrypted, errors kept, and disconnecting keeps the variables.
     *
     * @return void
     */
    public function test_secrets_sync_from_password_managers(): void
    {
        $this->withoutMiddleware(RequirePassword::class);
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.216.34']);
        $doppler = ['STRIPE_SECRET' => 'sk_1', 'DB_PASSWORD' => 'pw', 'APP_NAME' => 'from doppler', 'bad-key' => 'x'];
        Http::fake(function (Request $request) use (&$doppler) {
            return match (true) {
                str_starts_with($request->url(), 'https://api.doppler.com/') => $request->hasHeader('Authorization', 'Bearer dp.st.secret') ? Http::response($doppler) : Http::response(['messages' => ['Invalid token']], 401),
                str_starts_with($request->url(), 'https://connect.example.com/v1/vaults/v1/items/i1') => Http::response(['fields' => [['label' => 'MAIL_PASSWORD', 'value' => 'mail-pw'], ['label' => 'notes', 'value' => 'ignored']]]),
                str_starts_with($request->url(), 'https://secretsmanager.eu-west-2.amazonaws.com/') => str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'AWS4-HMAC-SHA256') && $request->header('X-Amz-Target')[0] === 'secretsmanager.GetSecretValue'
                    ? Http::response(['SecretString' => json_encode(['REDIS_PASSWORD' => 'redis-pw'])]) : Http::response(['message' => 'bad'], 400),
                default => Http::response('', 404),
            };
        });
        $project = Project::factory()->withServices(['deploy'])->create();
        $owner = $this->ownerOf($project);
        $this->onTier($project, 'deploy', 'pro');
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        (new EnvironmentVariable)->forceFill(['environment_id' => $environment->id, 'key' => 'APP_NAME', 'value' => 'Shop', 'is_secret' => false, 'scope' => 'runtime', 'current_version' => 1])->save();
        $url = "/api/app/projects/{$project->id}/deploy/environments/{$environment->id}/secret-syncs";

        $this->actingAs($owner)->postJson($url, ['provider' => 'doppler'])->assertJsonValidationErrors('provider');
        $this->actingAs($owner)->postJson($url, ['provider' => 'doppler', 'name' => 'Doppler prod', 'token' => 'dp.st.secret'])->assertSuccessful();
        $sync = SecretSync::query()->sole();
        $this->assertStringNotContainsString('dp.st.secret', (string) $sync->getRawOriginal('settings'));
        $managed = fn (): array => EnvironmentVariable::query()->where('environment_id', $environment->id)->where('secret_sync_id', $sync->id)->orderBy('key')->pluck('value', 'key')->all();
        $this->assertSame(['DB_PASSWORD' => 'pw', 'STRIPE_SECRET' => 'sk_1'], $managed());
        $this->assertSame('Shop', EnvironmentVariable::query()->where('key', 'APP_NAME')->value('value'), 'Hand-set variables stay.');
        $this->assertSame(['added' => 2, 'updated' => 0, 'removed' => 0, 'skipped' => ['APP_NAME', 'bad-key']], $sync->refresh()->last_result);
        $this->assertTrue((bool) EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->value('is_secret'));

        $doppler = ['STRIPE_SECRET' => 'sk_2'];
        app(SyncSecrets::class)->handle($sync);
        $this->assertSame(['STRIPE_SECRET' => 'sk_2'], $managed());
        $this->assertSame(2, EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->value('current_version'));
        $this->assertSame(['added' => 0, 'updated' => 1, 'removed' => 1, 'skipped' => []], $sync->refresh()->last_result);

        $sync->forceFill(['settings' => ['token' => 'wrong']])->save();
        $this->assertNull(app(SyncSecrets::class)->handle($sync));
        $this->assertStringContainsString('Invalid token', (string) $sync->refresh()->last_error);
        $this->assertSame(['STRIPE_SECRET' => 'sk_2'], $managed(), 'A failed sync changes nothing.');

        $this->actingAs($owner)->postJson($url, ['provider' => 'onepassword', 'host' => 'https://connect.example.com', 'token' => 'op-token', 'vault' => 'v1', 'item' => 'i1'])->assertSuccessful();
        $this->actingAs($owner)->postJson($url, ['provider' => 'aws', 'region' => 'eu-west-2', 'access_key' => 'AKIA1', 'secret_key' => 'secret', 'secret_id' => 'prod/app'])->assertSuccessful();
        $this->assertSame('mail-pw', EnvironmentVariable::query()->where('key', 'MAIL_PASSWORD')->value('value'));
        $this->assertSame('redis-pw', EnvironmentVariable::query()->where('key', 'REDIS_PASSWORD')->value('value'));
        $this->actingAs($owner)->getJson("/api/app/projects/{$project->id}/deploy/environments/{$environment->id}")->assertOk()->assertSee('Doppler prod')->assertSee('AWS Secrets Manager')->assertDontSee('op-token');

        $this->actingAs($owner)->deleteJson("{$url}/{$sync->id}")->assertSuccessful();
        $this->assertSame('sk_2', EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->value('value'), 'Disconnecting keeps the variables.');
        $this->assertNull(EnvironmentVariable::query()->where('key', 'STRIPE_SECRET')->value('secret_sync_id'));
    }
}

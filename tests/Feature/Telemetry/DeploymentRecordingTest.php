<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Actions\Telemetry\RecordDeployment;
use App\Enums\AccountRole;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\IngestToken;
use App\Models\Release;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class DeploymentRecordingTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_records_an_environment_scoped_api_deployment_with_explicit_utc_time_and_no_telemetry_usage(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $token = $this->collector();
        $foreign = Environment::factory()->create();

        $response = $this->postJson(route('api.deployments.store'), $this->payload([
            'version' => ' v1 ', 'service' => ' api ', 'service_namespace' => ' shop ',
            'commit_sha' => 'ABCDEF1', 'deployed_at' => '2026-09-21T14:00:00.123456+02:00',
            'note' => 'token=secret-value', 'environment_id' => $foreign->id,
            'actor_id' => $this->ownerOf($foreign)->id, 'source' => 'manual',
        ]));
        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.environment_id', $token->environment_id)
            ->assertJsonPath('data.version', 'v1')->assertJsonPath('data.commit_sha', 'abcdef1')
            ->assertJsonPath('data.deployed_at', '2026-09-21T12:00:00.123456Z')->assertJsonPath('data.replayed', false);

        $deployment = Deployment::sole();
        $this->assertSame($token->id, $deployment->ingest_token_id);
        $this->assertNull($deployment->actor_id);
        $this->assertSame('api', $deployment->source);
        $this->assertStringNotContainsString('secret-value', (string) $deployment->note);
        $this->assertSame($token->environment->project_id, $deployment->release->project_id);
        $this->assertSame('api', $deployment->release->service);
        $this->assertSame('shop', $deployment->release->service_namespace);
        $this->assertNull($deployment->release->first_seen_at);
        foreach (['telemetry_events', 'telemetry_usage_entries', 'ingest_receipts', 'jobs'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
        $this->assertSame(['id', 'deployment_id', 'environment_id', 'release_id', 'version', 'service', 'service_namespace', 'commit_sha', 'deployed_at', 'replayed'], array_keys($response->json('data')));
    }

    public function test_identical_retry_keeps_original_timestamp_and_creates_no_duplicate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $this->collector();
        $this->postJson(route('api.deployments.store'), $this->payload())->assertCreated();
        $this->travel(2)->hours();

        $this->postJson(route('api.deployments.store'), $this->payload(['deployment_id' => strtoupper($this->payload()['deployment_id'])]))
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.deployed_at', '2026-09-21T12:00:00.000000Z');

        $this->assertDatabaseCount('deployments', 1);
        $this->assertDatabaseCount('releases', 1);
    }

    /**
     * @param  array<mixed>  $change
     */
    #[DataProvider('conflictingDetails')]
    public function test_returns_409_if_an_existing_deployment_id_is_reused_with_different_details(array $change): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $this->collector();
        $this->postJson(route('api.deployments.store'), $this->payload())->assertCreated();

        $this->postJson(route('api.deployments.store'), $this->payload($change))->assertConflict()
            ->assertJsonPath('message', 'This deployment ID was already used with different details. Retry the original payload unchanged.');

        $this->assertDatabaseCount('deployments', 1);
        $this->assertDatabaseCount('releases', 1);
        $this->assertSame('v1', Release::sole()->version);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function conflictingDetails(): array
    {
        return [
            'version' => [['version' => 'v2']], 'service' => [['service' => 'worker']],
            'namespace' => [['service_namespace' => 'other']], 'commit' => [['commit_sha' => '123abcd']],
            'note' => [['note' => 'Different note']], 'time' => [['deployed_at' => '2026-09-21T12:00:00Z']],
        ];
    }

    public function test_replay_survives_app_key_rotation_when_the_previous_key_is_retained(): void
    {
        $this->collector();
        $this->postJson(route('api.deployments.store'), $this->payload())->assertCreated();
        config(['app.previous_keys' => [config('app.key')], 'app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

        $this->postJson(route('api.deployments.store'), $this->payload())->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_same_uuid_is_independent_per_environment_and_rollbacks_create_a_new_record_for_the_existing_release(): void
    {
        $first = $this->collector()->environment;
        $this->postJson(route('api.deployments.store'), $this->payload())->assertCreated();
        $this->postJson(route('api.deployments.store'), $this->payload(['deployment_id' => '588d598a-dc92-4502-9bd3-2710bb1dfd5a', 'version' => 'v2']))->assertCreated();
        $this->postJson(route('api.deployments.store'), $this->payload(['deployment_id' => 'ea65e2fb-134d-4c6e-970a-3ca85f474d74']))->assertCreated();
        $second = Environment::factory()->for($first->project)->create(['slug' => 'staging']);
        $this->collector($second);

        $this->postJson(route('api.deployments.store'), $this->payload())->assertCreated()->assertJsonPath('data.environment_id', $second->id);

        $this->assertDatabaseCount('deployments', 4);
        $this->assertDatabaseCount('releases', 2);
        $this->assertSame(3, Release::query()->where('version', 'v1')->sole()->deployments()->count());
    }

    /**
     * @param  array<mixed>  $change
     */
    #[DataProvider('invalidPayloads')]
    public function test_returns_422_with_a_useful_message_for_invalid_deployment_details(array $change, string $field, string $message): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:00Z'));
        $this->collector();

        $this->postJson(route('api.deployments.store'), $this->payload($change))->assertUnprocessable()
            ->assertJsonValidationErrors([$field])->assertJsonPath('errors.'.$field.'.0', $message);

        foreach (['releases', 'deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing UUID' => [['deployment_id' => null], 'deployment_id', 'Supply a deployment UUID and reuse it when retrying.'],
            'invalid UUID' => [['deployment_id' => 'not-a-uuid'], 'deployment_id', 'The deployment ID must be a valid UUID.'],
            'missing version' => [['version' => null], 'version', 'The version field is required.'],
            'non-string version' => [['version' => 12], 'version', 'The version field must be a string.'],
            'long version' => [['version' => str_repeat('v', 129)], 'version', 'The version field must not be greater than 128 characters.'],
            'control version' => [['version' => "v1\n"], 'version', 'Use a non-redacted release label without control characters.'],
            'redacted version' => [['version' => '[REDACTED]'], 'version', 'Use a non-redacted release label without control characters.'],
            'non-string service' => [['service' => []], 'service', 'The service field must be a string.'],
            'long service' => [['service' => str_repeat('s', 101)], 'service', 'The service field must not be greater than 100 characters.'],
            'namespace control' => [['service_namespace' => "shop\t"], 'service_namespace', 'Use a non-redacted release label without control characters.'],
            'long namespace' => [['service_namespace' => str_repeat('n', 101)], 'service_namespace', 'The service namespace field must not be greater than 100 characters.'],
            'invalid commit' => [['commit_sha' => 'not-a-commit'], 'commit_sha', 'Use a hexadecimal commit ID between 7 and 64 characters.'],
            'long note' => [['note' => str_repeat('n', 1001)], 'note', 'The note field must not be greater than 1000 characters.'],
            'timezone missing' => [['deployed_at' => '2026-09-21T12:00:00'], 'deployed_at', 'Use an ISO 8601 timestamp with seconds and a timezone, such as 2026-09-21T12:00:00Z.'],
            'relative timestamp' => [['deployed_at' => 'yesterday'], 'deployed_at', 'Use an ISO 8601 timestamp with seconds and a timezone, such as 2026-09-21T12:00:00Z.'],
            'future timestamp' => [['deployed_at' => '2026-09-21T12:05:01Z'], 'deployed_at', 'The deployment time cannot be more than five minutes in the future.'],
            'old timestamp' => [['deployed_at' => '1999-12-31T23:59:59Z'], 'deployed_at', 'The deployment time must be on or after 2000-01-01 UTC.'],
            'invalid day' => [['deployed_at' => '2026-02-30T12:00:00Z'], 'deployed_at', 'The deployed at field must be a valid date.'],
            'invalid seconds' => [['deployed_at' => '2026-09-21T11:59:60Z'], 'deployed_at', 'The deployed at field must be a valid date.'],
            'overflowing hour' => [['deployed_at' => '2026-09-20T24:00:00Z'], 'deployed_at', 'The deployed at field must be a valid date.'],
            'non-string namespace' => [['service_namespace' => 12], 'service_namespace', 'The service namespace field must be a string.'],
            'non-string note' => [['note' => ['nested']], 'note', 'The note field must be a string.'],
            'secret version' => [['version' => 'token=private-release'], 'version', 'Use non-secret, non-redacted release labels.'],
        ];
    }

    public function test_api_rejects_query_string_fields_without_creating_a_record(): void
    {
        $this->collector();

        $this->postJson(route('api.deployments.store', $this->payload()), ['unrelated' => true])->assertUnprocessable()
            ->assertJsonValidationErrors(['deployment_id', 'version']);

        foreach (['deployments', 'releases'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_returns_401_without_an_ingestion_token_even_for_a_signed_in_owner(): void
    {
        $environment = Environment::factory()->create();

        $this->actingAs($this->ownerOf($environment))->postJson(route('api.deployments.store'), $this->payload())->assertUnauthorized();

        $this->assertDatabaseEmpty('deployments');
    }

    #[DataProvider('disabledSources')]
    public function test_returns_401_for_an_inactive_collection_source(string $mode): void
    {
        $token = $this->collector();
        match ($mode) {
            'revoked' => $token->forceFill(['revoked_at' => now()])->save(),
            'expired' => $token->forceFill(['expires_at' => now()->subMinute()])->save(),
            'monitoring off' => $token->environment->project->enabledServices()->where('service', 'monitoring')->delete(),
            default => throw new LogicException($mode),
        };

        $this->postJson(route('api.deployments.store'), $this->payload())->assertUnauthorized();

        foreach (['releases', 'deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function disabledSources(): array
    {
        return array_map(fn (string $mode): array => [$mode], ['revoked', 'expired', 'monitoring off']);
    }

    public function test_recording_service_rechecks_token_revocation_after_authentication(): void
    {
        $token = $this->collector();
        IngestToken::query()->whereKey($token->id)->update(['revoked_at' => now()]);

        try {
            app(RecordDeployment::class)->handle($token->environment, $this->payload(), token: $token);
            $this->fail('Revoked token was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
        foreach (['releases', 'deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_api_limit_returns_429_without_new_records(): void
    {
        $this->collector();
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson(route('api.deployments.store'), $this->payload(['version' => null]))->assertUnprocessable();
        }

        $this->postJson(route('api.deployments.store'), $this->payload())->assertTooManyRequests();
        $this->assertDatabaseEmpty('deployments');

        $other = $this->collector();
        $this->postJson(route('api.deployments.store'), $this->payload())->assertCreated()
            ->assertJsonPath('data.environment_id', $other->environment_id);
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_database_failure_rolls_back_new_release_and_deployment_together(): void
    {
        $this->collector();
        DB::unprepared("CREATE TRIGGER reject_deployment BEFORE INSERT ON deployments BEGIN SELECT RAISE(ABORT, 'write unavailable'); END");
        Exceptions::fake();

        $this->postJson(route('api.deployments.store'), $this->payload())->assertInternalServerError();

        Exceptions::assertReported(QueryException::class);
        foreach (['releases', 'deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    public function test_gzip_transport_records_the_deployment_and_bounds_expansion(): void
    {
        $token = $this->collector();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_ENCODING' => 'gzip', 'HTTP_AUTHORIZATION' => 'Bearer deployment-test-'.$token->environment_id];

        $this->call('POST', route('api.deployments.store'), server: $headers, content: (string) gzencode(json_encode($this->payload(), JSON_THROW_ON_ERROR)))
            ->assertCreated()->assertJsonPath('data.version', 'v1');
        config(['monitoring.telemetry.max_decoded_bytes' => 100]);
        $this->call('POST', route('api.deployments.store'), server: $headers, content: (string) gzencode(str_repeat(' ', 101).'{}'))
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry request exceeds the decoded-size limit.');

        $this->assertDatabaseCount('deployments', 1);
    }

    #[DataProvider('badTransports')]
    public function test_rejects_malformed_deployment_transport_before_recording(string $body, string $contentType, int $status): void
    {
        $token = $this->collector();

        $this->call('POST', route('api.deployments.store'), server: [
            'CONTENT_TYPE' => $contentType, 'HTTP_AUTHORIZATION' => 'Bearer deployment-test-'.$token->environment_id,
        ], content: $body)->assertStatus($status);

        foreach (['releases', 'deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function badTransports(): array
    {
        return [['{', 'application/json', 400], ['[]', 'application/json', 400], ['{}', 'text/plain', 415]];
    }

    public function test_rechecks_member_demotion_inside_the_recording_transaction(): void
    {
        $environment = Environment::factory()->create();
        $member = User::factory()->create();
        $membership = $this->addMember($environment, $member, AccountRole::Member);
        $membership->forceFill(['role' => AccountRole::Viewer])->save();

        try {
            app(RecordDeployment::class)->handle($environment, $this->payload(), actor: $member);
            $this->fail('Demoted member was accepted.');
        } catch (AuthorizationException $exception) {
            $this->assertNull($exception->status());
        }
        foreach (['releases', 'deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    private function collector(?Environment $environment = null): IngestToken
    {
        $environment ??= Environment::factory()->create();
        $secret = 'deployment-test-'.$environment->id;
        $token = IngestToken::factory()->for($environment)->withSecret($secret)->create();
        $this->withToken($secret);

        return $token;
    }

    /**
     * @param  array<mixed>  $overrides
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{deployment_id: string, version: string, service?: string|null}
     */
    private function payload(array $overrides = []): array
    {
        return array_replace(['deployment_id' => '85e466e4-9c53-4f2c-a9c8-d987bbfce552', 'version' => 'v1', 'service' => 'api'], $overrides);
    }
}

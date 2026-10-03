<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\IngestToken;
use App\Models\Issue;
use App\Models\TelemetryEvent;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class TelemetryRedactionTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_credentials_and_personal_fields_are_redacted_before_events_and_issues_are_persisted(): void
    {
        IngestToken::factory()->withSecret('redaction-key')->create();

        $this->withToken('redaction-key')->postJson(route('api.ingest'), [
            'batch_id' => 'private-event',
            'events' => [[
                'id' => 'exception-1', 'type' => 'exception',
                'name' => 'Failure password=message-secret',
                'title' => 'Failure api_key=title-secret',
                'details' => 'Remote request Authorization: Bearer detail-secret',
                'attributes' => [
                    'db.password' => 'database-secret', 'api_key' => 'api-secret',
                    'credentials' => ['clientSecret' => 'client-secret', 'refresh_token' => 'refresh-secret'],
                    'user.email' => 'private@example.test', 'http.status_code' => 503,
                ],
                'payload' => ['headers' => [
                    'Authorization' => 'Bearer header-secret', 'Cookie' => 'session=cookie-secret',
                ]],
            ]],
        ])->assertOk()->assertJsonPath('data.accepted', 1);

        $event = TelemetryEvent::sole();
        $issue = Issue::sole();
        $this->assertSame('Failure password=[REDACTED]', $event->name);
        $this->assertSame('Failure api_key=[REDACTED]', $issue->title);
        $this->assertSame('Remote request Authorization: Bearer [REDACTED]', $issue->details);
        $this->assertSame('[REDACTED]', ($event->attributes ?? [])['db.password']);
        $this->assertSame('[REDACTED]', ($event->attributes ?? [])['credentials']['clientSecret']);
        $this->assertSame('[REDACTED]', ($event->attributes ?? [])['user.email']);
        $this->assertSame(503, ($event->attributes ?? [])['http.status_code']);
        $this->assertSame('[REDACTED]', ($event->payload ?? [])['headers']['Authorization']);
        $this->assertSame('[REDACTED]', ($issue->metadata ?? [])['api_key']);
        $stored = $event->toJson().$issue->toJson();

        foreach (['message-secret', 'title-secret', 'detail-secret', 'database-secret', 'api-secret', 'client-secret', 'refresh-secret', 'private@example.test', 'header-secret', 'cookie-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    #[DataProvider('sensitiveNames')]
    public function test_sensitive_keys_are_matched_case_insensitively_across_naming_conventions(string $name): void
    {
        IngestToken::factory()->withSecret('naming-key')->create();

        $this->withToken('naming-key')->postJson(route('api.ingest'), [
            'batch_id' => 'key-naming', 'events' => [['type' => 'log', 'attributes' => [$name => 'private-field-value']]],
        ])->assertOk();

        $this->assertSame('[REDACTED]', (TelemetryEvent::sole()->attributes ?? [])[$name]);
        $this->assertStringNotContainsString('private-field-value', TelemetryEvent::sole()->toJson());
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function sensitiveNames(): array
    {
        return [
            'password confirmation' => ['password_confirmation'],
            'uppercase header' => ['HTTP.Request.Header.AUTHORIZATION'],
            'camelcase key' => ['providerApiKey'],
            'session ID' => ['session_id'],
            'token suffix' => ['accessToken'],
            'private key' => ['private.key'],
            'credit card' => ['payment.card_number'],
            'email' => ['customer.email_address'],
            'database DSN' => ['db.connection_string'],
        ];
    }

    public function test_urls_have_credentials_and_sensitive_query_values_removed_but_keep_useful_query_values(): void
    {
        IngestToken::factory()->withSecret('url-key')->create();

        $this->withToken('url-key')->postJson(route('api.ingest'), [
            'batch_id' => 'url-values', 'events' => [[
                'type' => 'request',
                'url' => 'https://user:login-secret@example.test/search?%61pi_key=query-secret&page=2&token=token-secret',
                'payload' => ['request_url' => 'https://user:login-secret@example.test/?password=encoded-secret&sort=asc'],
            ]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame('https://[REDACTED]@example.test/search?%61pi_key=[REDACTED]&page=2&token=[REDACTED]', $event->route);
        $this->assertSame('https://[REDACTED]@example.test/?password=[REDACTED]&sort=asc', ($event->payload ?? [])['request_url']);
        $this->assertStringNotContainsString('secret', $event->toJson());
    }

    public function test_known_authentication_text_and_beacon_keys_are_scrubbed_from_log_messages(): void
    {
        $secret = 'bcn_'.str_repeat('a', 64);
        IngestToken::factory()->withSecret($secret)->create();

        $this->withToken($secret)->postJson(route('api.ingest'), [
            'batch_id' => 'credential-text', 'events' => [[
                'type' => 'log',
                'name' => 'Leaked '.$secret,
                'payload' => ['message' => 'Authorization Basic dXNlcjpwYXNzd29yZA==; password="quoted private value"; secret=another-private-value'],
            ]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame('Leaked [REDACTED]', $event->name);
        $this->assertSame('Authorization Basic [REDACTED]; password="[REDACTED]"; secret=[REDACTED]', ($event->payload ?? [])['message']);
        $this->assertStringNotContainsString($secret, $event->toJson());
    }

    public function test_redaction_does_not_change_metric_numbers_booleans_empty_values_or_token_counts(): void
    {
        IngestToken::factory()->withSecret('preservation-key')->create();

        $this->withToken('preservation-key')->postJson(route('api.ingest'), [
            'batch_id' => 'preserve-values', 'events' => [[
                'type' => 'metric', 'name' => 'gen_ai.client.token.usage',
                'attributes' => ['gen_ai.usage.input_tokens' => 123, 'token_count' => 456, 'cached' => false, 'label' => ''],
                'payload' => ['value' => 12.75, 'samples' => [0, false, '', null, 'ordinary text']],
            ]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame(['gen_ai.usage.input_tokens' => 123, 'token_count' => 456, 'cached' => false, 'label' => ''], $event->attributes);
        $this->assertSame(['value' => 12.75, 'samples' => [0, false, '', null, 'ordinary text']], $event->payload);
    }

    public function test_configured_paths_are_scrubbed_through_nested_records_and_typed_otlp_values(): void
    {
        config(['monitoring.telemetry.redacted_paths' => ['*.customer_id']]);
        IngestToken::factory()->withSecret('custom-redaction-key')->create();

        $this->withToken('custom-redaction-key')->postJson(route('api.ingest'), [
            'batch_id' => 'custom-policy', 'events' => [[
                'type' => 'log',
                'attributes' => ['customer_id' => 'private-customer'],
                'payload' => ['items' => [['customer_id' => 'private-nested-customer']], 'typed' => [
                    ['key' => 'customer_id', 'value' => ['stringValue' => 'private-typed-customer']],
                ]],
            ]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame('[REDACTED]', ($event->attributes ?? [])['customer_id']);
        $this->assertSame('[REDACTED]', ($event->payload ?? [])['items'][0]['customer_id']);
        $this->assertSame(['stringValue' => '[REDACTED]'], ($event->payload ?? [])['typed'][0]['value']);
        $this->assertStringNotContainsString('private-', $event->toJson());
    }

    public function test_otlp_resources_attributes_and_original_payloads_are_all_redacted(): void
    {
        IngestToken::factory()->withSecret('otlp-redaction-key')->create();

        $this->withToken('otlp-redaction-key')->postJson(route('api.otlp', 'traces'), [
            'resourceSpans' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                    ['key' => 'client.secret', 'value' => ['stringValue' => 'resource-private-value']],
                ]],
                'scopeSpans' => [['spans' => [[
                    'traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16),
                    'name' => 'POST /payments', 'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000001000000',
                    'attributes' => [
                        ['key' => 'http.request.header.authorization', 'value' => ['stringValue' => 'raw-private-value']],
                        ['key' => 'exception.message', 'value' => ['stringValue' => 'Failure password=exception-private-value']],
                        ['key' => 'exception.stacktrace', 'value' => ['stringValue' => 'at client api_key=stack-private-value']],
                    ],
                ]]]],
            ]],
        ])->assertSuccessful();

        $event = TelemetryEvent::sole();
        $this->assertSame('checkout', $event->service);
        $this->assertSame('[REDACTED]', ($event->payload ?? [])['resource_attributes']['client.secret']);
        $this->assertSame(['stringValue' => '[REDACTED]'], ($event->payload ?? [])['span']['attributes'][0]['value']);
        $this->assertStringNotContainsString('private-value', $event->toJson().Issue::sole()->toJson());
    }

    public function test_a_redacted_retry_does_not_create_extra_events_or_issues(): void
    {
        $token = IngestToken::factory()->withSecret('retry-redaction-key')->create();
        $payload = ['batch_id' => 'redacted-retry', 'events' => [[
            'id' => 'one-exception', 'type' => 'exception', 'name' => 'Error password=private-value',
            'attributes' => ['api_key' => 'private-key'],
        ]]];

        $this->withToken('retry-redaction-key')->postJson(route('api.ingest'), $payload)->assertJsonPath('data.accepted', 1);
        $this->postJson(route('api.ingest'), $payload)->assertJsonPath('data.duplicates', 1);

        $this->assertDatabaseCount('telemetry_events', 1);
        $this->assertDatabaseCount('issues', 1);
        $this->assertSame(1, Issue::sole()->occurrences);
        $this->assertSame(1, $token->environment->telemetry_event_count);
        $this->assertStringNotContainsString('private-', TelemetryEvent::sole()->toJson().Issue::sole()->toJson());
    }

    public function test_escaped_quotes_do_not_expose_the_rest_of_a_secret_assignment(): void
    {
        IngestToken::factory()->withSecret('escaped-redaction-key')->create();

        $this->withToken('escaped-redaction-key')->postJson(route('api.ingest'), [
            'batch_id' => 'quoted-secret', 'events' => [[
                'type' => 'log', 'payload' => ['message' => 'password="first \"private suffix"; token=another-private-value'],
            ]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame('password="[REDACTED]"; token=[REDACTED]', ($event->payload ?? [])['message']);
        $this->assertStringNotContainsString('private', $event->toJson());
    }

    public function test_full_redacted_urls_are_retained_when_the_searchable_route_has_to_be_shortened(): void
    {
        IngestToken::factory()->withSecret('long-url-key')->create();
        $url = 'https://example.test/'.str_repeat('a', 280).'?api_key=url-private-value';
        $safeUrl = 'https://example.test/'.str_repeat('a', 280).'?api_key=[REDACTED]';

        $this->withToken('long-url-key')->postJson(route('api.ingest'), [
            'batch_id' => 'long-url', 'events' => [[
                'type' => 'request', 'url' => $url,
                'payload' => ['request' => 'original content', '_beacon' => ['submitted' => 'preserved']],
            ]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame(255, mb_strlen((string) $event->route));
        $this->assertSame($safeUrl, ($event->payload ?? [])['_beacon']['indexed_fields']['url']);
        $this->assertSame('original content', ($event->payload ?? [])['request']);
        $this->assertSame(['submitted' => 'preserved'], ($event->payload ?? [])['_beacon']['submitted_metadata']);
        $this->assertStringNotContainsString('url-private-value', $event->toJson());
    }

    public function test_values_expanded_by_redaction_keep_a_full_copy_when_they_exceed_index_lengths(): void
    {
        IngestToken::factory()->withSecret('expanded-name-key')->create();
        $name = str_repeat('x', 244).' password=x';

        $this->withToken('expanded-name-key')->postJson(route('api.ingest'), [
            'batch_id' => 'long-name', 'events' => [['type' => 'exception', 'name' => $name]],
        ])->assertOk();

        $event = TelemetryEvent::sole();
        $this->assertSame(255, mb_strlen((string) $event->name));
        $this->assertSame(str_repeat('x', 244).' password=[REDACTED]', ($event->payload ?? [])['_beacon']['indexed_fields']['name']);
        $this->assertStringNotContainsString('password=x', $event->toJson().Issue::sole()->toJson());
    }

    public function test_the_storage_budget_also_covers_redaction_expansion(): void
    {
        config(['monitoring.telemetry.max_normalized_bytes' => 50]);
        $token = IngestToken::factory()->withSecret('redaction-size-key')->create();

        $this->withToken('redaction-size-key')->postJson(route('api.ingest'), [
            'batch_id' => 'redaction-expansion', 'events' => [['type' => 'log', 'attributes' => ['password' => 'x']]],
        ])->assertStatus(413)->assertJsonPath('message', 'Expanded telemetry batch exceeds the storage-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    public function test_regex_exhaustion_fails_closed_instead_of_preserving_a_secret(): void
    {
        $redactor = app(TelemetryRedactor::class);
        $previousLimit = ini_set('pcre.backtrack_limit', '0');

        try {
            $event = $redactor->redact(['name' => 'Bearer private-value']);
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }

        $this->assertSame('[REDACTED]', $event['name']);
    }
}

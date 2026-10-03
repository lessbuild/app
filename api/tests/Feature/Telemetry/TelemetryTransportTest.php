<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Models\IngestToken;
use App\Models\TelemetryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class TelemetryTransportTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    public function test_gzip_batches_are_decoded_without_changing_strings_and_retries_are_deduplicated(): void
    {
        $token = IngestToken::factory()->withSecret('gzip-key')->create();
        $body = '{"batch_id":"gzip-batch","events":[{"id":"first","type":"log","name":"  preserved text  ","attributes":{"empty":"","large":18446744073709551615}}]}';

        $this->send((string) gzencode($body), ['Authorization' => 'Bearer gzip-key', 'Content-Encoding' => 'gzip'])
            ->assertOk()->assertJsonPath('data.accepted', 1);

        $event = TelemetryEvent::sole();
        $this->assertSame('  preserved text  ', $event->name);
        $this->assertSame('', ($event->attributes ?? [])['empty']);
        $this->assertSame('18446744073709551615', ($event->attributes ?? [])['large']);
        $this->send((string) gzencode($body), ['Authorization' => 'Bearer gzip-key', 'Content-Encoding' => 'gzip'])
            ->assertOk()->assertJsonPath('data.accepted', 0)->assertJsonPath('data.duplicates', 1);
        $this->assertSame(1, $this->reload($token->environment)->telemetry_event_count);
        $this->assertDatabaseCount('telemetry_events', 1);
    }

    public function test_a_gzip_stream_split_across_multiple_members_is_fully_decoded(): void
    {
        IngestToken::factory()->withSecret('multipart-gzip')->create();
        $body = (string) gzencode('{"batch_id":"multi","events":').(string) gzencode('[{"type":"log","name":"Multipart gzip"}]}');

        $this->send($body, ['Authorization' => 'Bearer multipart-gzip', 'Content-Encoding' => 'gzip'])
            ->assertOk()->assertJsonPath('data.accepted', 1);

        $this->assertDatabaseHas('telemetry_events', ['name' => 'Multipart gzip']);
    }

    #[DataProvider('invalidGzip')]
    public function test_invalid_gzip_returns_400_without_writing_data(string $body): void
    {
        IngestToken::factory()->withSecret('gzip-key')->create();

        $this->send($body, ['Authorization' => 'Bearer gzip-key', 'Content-Encoding' => 'gzip'])
            ->assertBadRequest()->assertJsonStructure(['message']);

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertDatabaseCount('issues', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidGzip(): array
    {
        $compressed = (string) gzencode('{"batch_id":"damaged","events":[{"type":"log"}]}');

        return [
            'not gzip' => ['not gzip'],
            'truncated stream' => [substr($compressed, 0, -5)],
            'bad checksum' => [substr($compressed, 0, -8).str_repeat("\0", 8)],
            'trailing garbage' => [$compressed.'unconsumed bytes'],
            'empty stream' => [''],
        ];
    }

    #[DataProvider('invalidJson')]
    public function test_malformed_or_non_object_json_returns_400_without_echoing_the_body(string $body): void
    {
        IngestToken::factory()->withSecret('json-key')->create();

        $this->send($body, ['Authorization' => 'Bearer json-key'])
            ->assertBadRequest()->assertJsonStructure(['message'])->assertDontSee('body-secret-value');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function invalidJson(): array
    {
        return [
            'empty body' => [''],
            'broken syntax' => ['{"password":"body-secret-value",'],
            'array root' => ['[]'],
            'string root' => ['"body-secret-value"'],
            'numeric root' => ['42'],
            'null root' => ['null'],
            'invalid UTF-8' => ["{\"value\":\"\xB1\"}"],
            'trailing document' => ['{}{}'],
        ];
    }

    /**
     * @param  array<mixed>  $headers
     */
    #[DataProvider('unsupportedMedia')]
    public function test_unsupported_formats_return_415(array $headers): void
    {
        IngestToken::factory()->withSecret('format-key')->create();

        $this->send('{}', $headers + ['Authorization' => 'Bearer format-key'])
            ->assertUnsupportedMediaType()->assertJsonStructure(['message']);

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function unsupportedMedia(): array
    {
        return [
            'text' => [['Content-Type' => 'text/plain']],
            'form data' => [['Content-Type' => 'application/x-www-form-urlencoded']],
            'protobuf on JSON API' => [['Content-Type' => 'application/x-protobuf']],
            'brotli' => [['Content-Encoding' => 'br']],
            'stacked encoding' => [['Content-Encoding' => 'gzip, gzip']],
        ];
    }

    public function test_json_media_type_parameters_and_case_insensitive_encoding_are_accepted(): void
    {
        IngestToken::factory()->withSecret('parameters-key')->create();

        $this->send((string) gzencode($this->body()), [
            'Authorization' => 'Bearer parameters-key',
            'Content-Type' => 'Application/JSON; charset=utf-8',
            'Content-Encoding' => 'GZip',
        ])->assertOk();

        $this->assertDatabaseCount('telemetry_events', 1);
    }

    /**
     * @param  array<mixed>  $headers
     */
    #[DataProvider('wireLengthHeaders')]
    public function test_the_wire_limit_is_checked_against_actual_bytes_even_without_an_honest_header(array $headers): void
    {
        config(['monitoring.telemetry.max_request_bytes' => 64]);
        IngestToken::factory()->withSecret('size-key')->create();

        $this->send(str_repeat(' ', 65).'{}', $headers + ['Authorization' => 'Bearer size-key'])
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry request exceeds the wire-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    /**
     * @return array<array-key, list<mixed>>
     */
    public static function wireLengthHeaders(): array
    {
        return ['missing length' => [[]], 'understated length' => [['Content-Length' => '2']]];
    }

    public function test_an_oversized_content_length_is_rejected_without_reading_the_body(): void
    {
        config(['monitoring.telemetry.max_request_bytes' => 64]);

        $this->send('{}', ['Content-Length' => '65'])->assertStatus(413)
            ->assertJsonPath('message', 'Telemetry request exceeds the wire-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_compressed_expansion_is_bounded_before_json_parsing(): void
    {
        config(['monitoring.telemetry.max_decoded_bytes' => 128]);
        IngestToken::factory()->withSecret('expansion-key')->create();

        $this->send((string) gzencode(str_repeat(' ', 4096).'{}'), ['Authorization' => 'Bearer expansion-key', 'Content-Encoding' => 'gzip'])
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry request exceeds the decoded-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_uncompressed_requests_also_obey_the_decoded_size_limit(): void
    {
        config(['monitoring.telemetry.max_request_bytes' => 1024, 'monitoring.telemetry.max_decoded_bytes' => 64]);

        $this->send(str_repeat(' ', 65).'{}')->assertStatus(413)
            ->assertJsonPath('message', 'Telemetry request exceeds the decoded-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_payloads_exactly_at_the_byte_limits_are_accepted(): void
    {
        IngestToken::factory()->withSecret('boundary-key')->create();
        $body = $this->body();
        config(['monitoring.telemetry.max_request_bytes' => strlen($body), 'monitoring.telemetry.max_decoded_bytes' => strlen($body)]);

        $this->send($body, ['Authorization' => 'Bearer boundary-key'])->assertOk();

        $this->assertDatabaseCount('telemetry_events', 1);
    }

    public function test_json_nesting_is_bounded_before_any_event_is_accepted(): void
    {
        config(['monitoring.telemetry.max_json_depth' => 4]);
        IngestToken::factory()->withSecret('depth-key')->create();

        $this->send('{"batch_id":"deep","events":[{"type":"log","payload":{"nested":{"value":"secret"}}}]}', ['Authorization' => 'Bearer depth-key'])
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry request exceeds the nesting limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_decoded_gzip_still_requires_a_valid_ingestion_token(): void
    {
        $this->send((string) gzencode($this->body()), ['Content-Encoding' => 'gzip'])->assertUnauthorized();

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_query_parameters_cannot_supply_or_override_telemetry_fields(): void
    {
        IngestToken::factory()->withSecret('body-only-key')->create();
        $query = http_build_query(['batch_id' => 'from-query', 'events' => [['type' => 'log', 'name' => 'Not from body']]]);

        $this->send('{}', ['Authorization' => 'Bearer body-only-key'], route('api.ingest').'?'.$query)
            ->assertUnprocessable()->assertJsonValidationErrors([
                'batch_id' => 'The batch id field is required.',
                'events' => 'The events field is required.',
            ]);

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_event_batches_must_be_lists_and_respect_the_configured_event_limit(): void
    {
        config(['monitoring.telemetry.max_events_per_batch' => 1]);
        IngestToken::factory()->withSecret('count-key')->create();

        $this->withToken('count-key')->postJson(route('api.ingest'), [
            'batch_id' => 'associative', 'events' => ['not-an-index' => ['type' => 'log']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['events' => 'The events field must be a list.']);
        $this->postJson(route('api.ingest'), [
            'batch_id' => 'oversized', 'events' => [['type' => 'log'], ['type' => 'log']],
        ])->assertStatus(413)->assertJsonPath('message', 'Telemetry batch exceeds the event limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_gzip_is_supported_for_otlp_json_and_its_nested_strings_are_preserved(): void
    {
        IngestToken::factory()->withSecret('otlp-gzip-key')->create();
        $body = json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => [[
            'body' => ['stringValue' => '  OTLP message  '],
            'attributes' => [['key' => 'empty', 'value' => ['stringValue' => '']]],
        ]]]]]]], JSON_THROW_ON_ERROR);

        $this->send((string) gzencode($body), ['Authorization' => 'Bearer otlp-gzip-key', 'Content-Encoding' => 'gzip'], route('api.otlp', 'logs'))
            ->assertSuccessful();

        $this->assertSame('  OTLP message  ', TelemetryEvent::sole()->name);
        $this->assertSame('', (TelemetryEvent::sole()->attributes ?? [])['empty']);
    }

    public function test_structural_complexity_is_bounded_before_json_decoding(): void
    {
        config(['monitoring.telemetry.max_json_nodes' => 10]);

        $this->send('{"items":['.implode(',', array_fill(0, 20, '{"value":1}')).']}')
            ->assertStatus(413)->assertJsonPath('message', 'Telemetry request exceeds the structural complexity limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
    }

    public function test_punctuation_and_escaped_quotes_inside_strings_do_not_count_as_json_structure(): void
    {
        config(['monitoring.telemetry.max_json_nodes' => 12]);
        IngestToken::factory()->withSecret('structure-key')->create();
        $message = str_repeat(' \\" [{,: ', 20);

        $this->withToken('structure-key')->postJson(route('api.ingest'), [
            'batch_id' => 'text-structure', 'events' => [['type' => 'log', 'name' => $message]],
        ])->assertOk();

        $this->assertSame($message, TelemetryEvent::sole()->name);
    }

    public function test_expanded_batches_are_rejected_atomically_before_storage(): void
    {
        config(['monitoring.telemetry.max_normalized_bytes' => 32]);
        $token = IngestToken::factory()->withSecret('expanded-key')->create();

        $this->send($this->body(), ['Authorization' => 'Bearer expanded-key'])->assertStatus(413)
            ->assertJsonPath('message', 'Expanded telemetry batch exceeds the storage-size limit.');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    public function test_non_ingestion_forms_still_accept_form_data_and_normalize_input(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password-123']);

        $this->post(route('login.store'), ['email' => '  ada@example.com  ', 'password' => 'secret-password-123']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_numbers_that_overflow_the_runtime_are_rejected_before_storage(): void
    {
        $token = IngestToken::factory()->withSecret('numeric-range-key')->create();

        $this->send('{"batch_id":"numeric-range","events":[{"type":"metric","payload":{"value":1e999}}]}', [
            'Authorization' => 'Bearer numeric-range-key',
        ])->assertBadRequest()->assertJsonPath('message', 'Telemetry JSON numbers must be finite and within the supported numeric range.');

        $this->assertDatabaseCount('telemetry_events', 0);
        $this->assertSame(0, $token->environment->telemetry_event_count);
    }

    private function body(): string
    {
        return '{"batch_id":"transport-test","events":[{"id":"first","type":"log","name":"Transport event"}]}';
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $body, array $headers = [], ?string $url = null): TestResponse
    {
        return $this->call(
            'POST',
            $url ?? route('api.ingest'),
            server: $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']),
            content: $body,
        );
    }
}

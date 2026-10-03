<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsRecordResolver;
use App\Models\Monitor;
use App\Services\Monitoring\ProbeDnsMonitor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class DnsMonitorProbeTest extends TestCase
{
    use MonitoringHelpers;

    /**
     * @param  array<mixed>  $values
     */
    #[DataProvider('recordMatches')]
    public function test_evaluates_missing_and_extra_records(string $mode, array $values, string $outcome, int $missing, int $unexpected): void
    {
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->with('status.example.com', 'A', 10)
            ->andReturn(array_map(fn (string $value): array => ['type' => 'A', 'ip' => $value], $values));
        Http::preventStrayRequests();

        $result = app(ProbeDnsMonitor::class)->probe(Monitor::factory()->dns()->state(['environment_id' => 1])->make(['dns_match' => $mode]));

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($outcome === 'up' ? 'passed' : 'dns_mismatch', $result->reason);
        $this->assertSame($missing, $result->details['missing_count']);
        $this->assertSame($unexpected, $result->details['unexpected_count']);
        $this->assertNull($result->httpStatus);
        Http::assertNothingSent();
    }

    /** @return array<string, list<mixed>> */
    public static function recordMatches(): array
    {
        return [
            'exact pass' => ['exact', ['1.1.1.1'], 'up', 0, 0],
            'duplicates are one record' => ['exact', ['1.1.1.1', '1.1.1.1'], 'up', 0, 0],
            'exact extra' => ['exact', ['1.1.1.1', '8.8.8.8'], 'down', 0, 1],
            'contains extra' => ['contains', ['1.1.1.1', '8.8.8.8'], 'up', 0, 1],
            'missing' => ['contains', ['8.8.8.8'], 'down', 1, 1],
            'no answer' => ['exact', [], 'down', 1, 0],
        ];
    }

    public function test_keeps_raw_txt_values_out_of_public_observations(): void
    {
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andReturn([['type' => 'TXT', 'txt' => 'observed-secret']]);
        $monitor = Monitor::factory()->dns()->state(['environment_id' => 1])->make([
            'dns_record_type' => 'TXT', 'dns_expected' => ['expected-secret'],
        ]);

        $result = app(ProbeDnsMonitor::class)->probe($monitor);

        $this->assertSame(['expected-secret'], $result->evidence['missing']);
        $this->assertSame(['observed-secret'], $result->evidence['unexpected']);
        $this->assertStringNotContainsString('secret', (string) json_encode($result->toArray()));
    }

    public function test_resolver_failure_is_unknown_instead_of_a_missing_record_failure(): void
    {
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andReturnNull();

        $result = app(ProbeDnsMonitor::class)->probe(Monitor::factory()->dns()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('dns_unavailable', $result->reason);
        $this->assertSame([], $result->evidence);
    }

    public function test_resolver_exceptions_do_not_expose_raw_details(): void
    {
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andThrow(new RuntimeException('private resolver detail'));

        $result = app(ProbeDnsMonitor::class)->probe(Monitor::factory()->dns()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertStringNotContainsString('private resolver', (string) json_encode($result->toArray()));
    }

    public function test_malformed_answers_are_unknown_without_evidence(): void
    {
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andReturn([['type' => 'A', 'ip' => 'invalid']]);

        $result = app(ProbeDnsMonitor::class)->probe(Monitor::factory()->dns()->state(['environment_id' => 1])->make());

        $this->assertSame('dns_response_invalid', $result->reason);
        $this->assertSame('unknown', $result->outcome);
        $this->assertSame([], $result->evidence);
    }

    public function test_invalid_stored_configuration_never_queries_dns(): void
    {
        $this->mock(DnsRecordResolver::class)->shouldNotReceive('records');

        $result = app(ProbeDnsMonitor::class)->probe(Monitor::factory()->dns()->state(['environment_id' => 1])->make(['hostname' => 'localhost']));

        $this->assertSame('target_invalid', $result->reason);
        $this->assertSame('unknown', $result->outcome);
    }

    public function test_unexpected_private_style_alias_is_a_mismatch_without_connecting_to_it(): void
    {
        $this->mock(DnsRecordResolver::class)->shouldReceive('records')->once()->andReturn([['type' => 'CNAME', 'target' => 'service.internal.']]);
        Http::preventStrayRequests();
        $monitor = Monitor::factory()->dns()->state(['environment_id' => 1])->make([
            'dns_record_type' => 'CNAME', 'dns_expected' => ['public.example.com'],
        ]);

        $result = app(ProbeDnsMonitor::class)->probe($monitor);

        $this->assertSame('down', $result->outcome);
        $this->assertSame('dns_mismatch', $result->reason);
        $this->assertSame(['service.internal'], $result->evidence['unexpected']);
        Http::assertNothingSent();
    }
}

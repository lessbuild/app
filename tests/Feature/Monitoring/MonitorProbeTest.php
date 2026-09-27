<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Models\Monitor;
use App\Services\Monitoring\ProbeHttpMonitor;
use App\Services\Monitoring\PublicHttpTarget;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MonitorProbeTest extends TestCase
{
    use MonitoringHelpers;

    #[DataProvider('unsafeUrls')]
    public function test_rejects_non_public_or_ambiguous_targets(string $url): void
    {
        $this->assertNull(app(PublicHttpTarget::class)->parse($url));
    }

    /** @return array<string, list<mixed>> */
    public static function unsafeUrls(): array
    {
        $urls = ['file:///etc/passwd', 'ftp://example.com', 'https://user:password@example.com',
            'https://example.com/#secret', 'https://localhost/', 'http://127.0.0.1/', 'http://169.254.169.254/',
            'http://10.0.0.1/', 'http://100.64.1.1/', 'http://192.168.1.1/', 'http://198.18.0.1/',
            'http://2130706433/', 'http://0x7f000001/', 'http://0177.0.0.1/', 'http://127.1/',
            'http://[::1]/', 'http://[::ffff:1.1.1.1]/', 'http://[2002:7f00:1::1]/',
            'http://[fe80::1%25eth0]/', 'http://[2001:db8::1]/', 'http://[fc00::1]/',
            'https://example.com:0/', 'https://example.com:65536/', 'https://example.com./',
            'https://example.com\\@127.0.0.1/', "https://example.com/\r\nHost: localhost", 'https://éxample.com/'];

        return array_combine($urls, array_map(fn (string $url): array => [$url], $urls));
    }

    #[DataProvider('publicUrls')]
    public function test_accepts_public_domain_and_ip_endpoints_with_custom_ports(string $url, string $host, int $port): void
    {
        $parsed = app(PublicHttpTarget::class)->parse($url);

        $this->assertNotNull($parsed);
        $this->assertSame($host, $parsed['host']);
        $this->assertSame($port, $parsed['port']);
    }

    /** @return array<string, list<mixed>> */
    public static function publicUrls(): array
    {
        return [
            'domain' => ['https://STATUS.example.com/health?token=secret', 'status.example.com', 443],
            'http custom port' => ['http://1.1.1.1:8006/health', '1.1.1.1', 8006],
            'ipv6' => ['https://[2606:4700:4700::1111]/health', '2606:4700:4700::1111', 443],
        ];
    }

    public function test_pins_public_dns_disables_proxies_and_redirects_and_preserves_tls_verification(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->with('status.example.com')->andReturn(['2606:4700:4700::1111']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => function (Request $request, array $options) {
            $this->assertSame(['status.example.com:443:[2606:4700:4700::1111]'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame('', $options['proxy']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options['curl'][CURLOPT_PROTOCOLS]);
            $this->assertSame(10, $options['timeout']);

            return Http::response('healthy', 200);
        }]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make(['body_contains' => 'healthy']));

        $this->assertSame('up', $result->outcome);
        $this->assertSame('passed', $result->reason);
        $this->assertSame(200, $result->httpStatus);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
        $this->assertStringNotContainsString('healthy', (string) json_encode($result->toArray()));
        Http::assertSentCount(1);
    }

    #[DataProvider('responses')]
    public function test_evaluates_status_and_literal_response_assertions(int $status, string $body, ?string $required, string $outcome, string $reason): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::response($body, $status)]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make(['body_contains' => $required]));

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($reason, $result->reason);
        Http::assertSentCount(1);
    }

    /** @return array<string, list<mixed>> */
    public static function responses(): array
    {
        return [
            'healthy' => [200, 'ok', null, 'up', 'passed'],
            'empty accepted' => [204, '', null, 'up', 'passed'],
            'unfollowed redirect' => [302, '', null, 'down', 'unexpected_status'],
            'server failure' => [503, '', null, 'down', 'unexpected_status'],
            'missing body text' => [200, 'broken', 'healthy', 'down', 'body_mismatch'],
            'literal not regex' => [200, 'ready.*', 'ready.*', 'up', 'passed'],
        ];
    }

    /**
     * @param  array<mixed>  $addresses
     */
    #[DataProvider('unusableDns')]
    public function test_dns_failures_and_mixed_private_answers_are_unknown_without_sending(array $addresses, string $reason): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->andReturn($addresses);
        Http::preventStrayRequests();

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame($reason, $result->reason);
        Http::assertNothingSent();
    }

    /** @return array<string, list<mixed>> */
    public static function unusableDns(): array
    {
        return ['no result' => [[], 'dns_unavailable'], 'rebind' => [['1.1.1.1', '10.0.0.1'], 'target_not_public']];
    }

    public function test_connection_failure_is_a_failed_observation_without_retry_or_exception_details(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::failedConnection('secret error detail')]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make());

        $this->assertSame('down', $result->outcome);
        $this->assertSame('connection_failed', $result->reason);
        $this->assertStringNotContainsString('secret', (string) json_encode($result->toArray()));
    }

    public function test_oversized_response_is_unknown_without_persisting_a_body(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::response(str_repeat('x', ProbeHttpMonitor::BODY_LIMIT + 1))]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('response_too_large', $result->reason);
        $this->assertNull($result->httpStatus);
        Http::assertSentCount(1);
    }

    public function test_head_and_bearer_authentication_are_sent_to_the_configured_https_endpoint(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => Http::response('', 204)]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make(['method' => 'HEAD', 'bearer_token' => 'secret-token']));

        $this->assertSame('up', $result->outcome);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD' && $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_measured_transfer_time_enforces_latency_threshold_and_records_connection_timings(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::globalOptions([
            'on_stats' => static fn (TransferStats $stats): TransferStats => new TransferStats($stats->getRequest(), $stats->getResponse(), 0.25, null, [
                'total_time' => 0.25, 'connect_time' => 0.015, 'starttransfer_time' => 0.09,
            ]),
        ]);
        Http::fake(['https://status.example.com/health' => Http::response('ok', 200)]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make(['max_duration_ms' => 100]));

        $this->assertSame('down', $result->outcome);
        $this->assertSame('too_slow', $result->reason);
        $this->assertGreaterThanOrEqual(250, $result->durationMs);
        $this->assertSame(15.0, $result->connectMs);
        $this->assertSame(90.0, $result->ttfbMs);
        Http::assertSentCount(1);
    }

    public function test_streaming_body_limit_stops_unbounded_chunked_responses(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        Http::preventStrayRequests();
        Http::fake(['https://status.example.com/health' => function (Request $request, array $options) {
            $options['sink']->write(str_repeat('x', ProbeHttpMonitor::BODY_LIMIT));
            $options['sink']->write('x');

            return Http::response('unreachable');
        }]);

        $result = app(ProbeHttpMonitor::class)->probe(Monitor::factory()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('response_too_large', $result->reason);
    }
}

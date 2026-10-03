<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Contracts\Monitoring\TcpConnector;
use App\Models\Monitor;
use App\Services\Monitoring\NativeTcpConnector;
use App\Services\Monitoring\ProbeTcpMonitor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class TcpMonitorProbeTest extends TestCase
{
    use MonitoringHelpers;

    #[DataProvider('publicAddresses')]
    public function test_connects_to_the_pinned_public_address_without_sending_an_application_payload(string $pinned): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->with('status.example.com')->andReturn([$pinned]);
        $this->mock(TcpConnector::class)->shouldReceive('connect')->once()->withArgs(
            fn (string $address, int $port, int $timeout): bool => $address === $pinned && $port === 5432 && $timeout > 0 && $timeout <= 10000
        )->andReturnTrue();

        $result = app(ProbeTcpMonitor::class)->probe(Monitor::factory()->tcp()->state(['environment_id' => 1])->make());

        $this->assertSame('up', $result->outcome);
        $this->assertSame('tcp_connected', $result->reason);
        $this->assertSame(['type' => 'tcp', 'port' => 5432], $result->details);
        $this->assertNotNull($result->durationMs);
        $this->assertNotNull($result->dnsMs);
        $this->assertNotNull($result->connectMs);
        $this->assertNull($result->httpStatus);
    }

    /** @return array<string, list<mixed>> */
    public static function publicAddresses(): array
    {
        return ['IPv4' => ['1.1.1.1'], 'IPv6' => ['2606:4700:4700::1111']];
    }

    public function test_connection_failures_are_down_but_do_not_expose_transport_errors(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        $this->mock(TcpConnector::class)->shouldReceive('connect')->once()->andReturnFalse();

        $result = app(ProbeTcpMonitor::class)->probe(Monitor::factory()->tcp()->state(['environment_id' => 1])->make());

        $this->assertSame('down', $result->outcome);
        $this->assertSame('tcp_connection_failed', $result->reason);
        $this->assertStringNotContainsString('socket', (string) json_encode($result->toArray()));
    }

    /**
     * @param  array<mixed>  $addresses
     */
    #[DataProvider('unsafeAnswers')]
    public function test_refuses_dns_failures_and_private_or_mixed_answers_before_connecting(array $addresses, string $reason): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->andReturn($addresses);
        $this->mock(TcpConnector::class)->shouldNotReceive('connect');

        $result = app(ProbeTcpMonitor::class)->probe(Monitor::factory()->tcp()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame($reason, $result->reason);
    }

    /** @return array<string, list<mixed>> */
    public static function unsafeAnswers(): array
    {
        return [
            'DNS failure' => [[], 'dns_unavailable'],
            'private IPv4' => [['127.0.0.1'], 'target_not_public'],
            'mixed answer rebind' => [['1.1.1.1', '169.254.169.254'], 'target_not_public'],
            'IPv4 mapped IPv6' => [['::ffff:127.0.0.1'], 'target_not_public'],
        ];
    }

    public function test_resolver_exceptions_are_unknown_and_do_not_reach_the_connector(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->andThrow(new RuntimeException('private resolver detail'));
        $this->mock(TcpConnector::class)->shouldNotReceive('connect');

        $result = app(ProbeTcpMonitor::class)->probe(Monitor::factory()->tcp()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('dns_unavailable', $result->reason);
        $this->assertStringNotContainsString('private resolver detail', (string) json_encode($result->toArray()));
    }

    public function test_connector_exceptions_are_unknown_without_exposing_the_exception_message(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        $this->mock(TcpConnector::class)->shouldReceive('connect')->once()->andThrow(new RuntimeException('private socket detail'));

        $result = app(ProbeTcpMonitor::class)->probe(Monitor::factory()->tcp()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('checker_unavailable', $result->reason);
        $this->assertStringNotContainsString('private socket detail', (string) json_encode($result->toArray()));
    }

    /**
     * @param  array<mixed>  $configuration
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_stored_configuration_is_rejected_before_resolving(array $configuration): void
    {
        $this->mock(DnsResolver::class)->shouldNotReceive('addresses');
        $this->mock(TcpConnector::class)->shouldNotReceive('connect');

        $result = app(ProbeTcpMonitor::class)->probe(Monitor::factory()->tcp()->state(['environment_id' => 1])->make($configuration));

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('target_invalid', $result->reason);
    }

    /** @return array<string, list<mixed>> */
    public static function invalidConfigurations(): array
    {
        return [
            'path injection' => [['hostname' => 'example.com/private']],
            'zero port' => [['tcp_port' => 0]],
            'missing port' => [['tcp_port' => null]],
            'zero timeout' => [['timeout_seconds' => 0]],
            'unbounded timeout' => [['timeout_seconds' => 21]],
        ];
    }

    #[DataProvider('unsafeTransportArguments')]
    public function test_native_connector_rejects_invalid_or_unpinned_transport_arguments(string $address, int $port, int $timeout): void
    {
        $this->mock(DnsResolver::class)->shouldNotReceive('addresses');
        $this->expectException(InvalidArgumentException::class);

        app(NativeTcpConnector::class)->connect($address, $port, $timeout);
    }

    /** @return array<string, list<mixed>> */
    public static function unsafeTransportArguments(): array
    {
        return [
            'zero port' => ['1.1.1.1', 0, 1000],
            'overflow port' => ['1.1.1.1', 65536, 1000],
            'zero timeout' => ['1.1.1.1', 5432, 0],
            'empty address' => ['', 5432, 1000],
            'unresolved hostname' => ['status.example.com', 5432, 1000],
            'loopback' => ['127.0.0.1', 5432, 1000],
            'cloud metadata' => ['169.254.169.254', 80, 1000],
            'private IPv6' => ['::1', 5432, 1000],
            'mapped IPv6' => ['::ffff:127.0.0.1', 5432, 1000],
        ];
    }
}

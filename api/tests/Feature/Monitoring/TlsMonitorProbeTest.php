<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Contracts\Monitoring\DnsResolver;
use App\Contracts\Monitoring\TlsCertificateInspector;
use App\Data\Monitoring\TlsCertificateInspection;
use App\Models\Monitor;
use App\Services\Monitoring\NativeTlsCertificateInspector;
use App\Services\Monitoring\ProbeTlsMonitor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class TlsMonitorProbeTest extends TestCase
{
    use MonitoringHelpers;

    #[DataProvider('expiryWindows')]
    public function test_classifies_verified_certificate_validity_against_the_warning_window(int $secondsUntilExpiry, int $secondsUntilValid, string $reason, string $outcome): void
    {
        $this->travelTo('2030-01-01 00:00:00');
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->with('status.example.com')->andReturn(['2606:4700:4700::1111']);
        $this->mock(TlsCertificateInspector::class)->shouldReceive('inspect')->once()->withArgs(
            fn (string $host, string $address, int $port, int $timeout): bool => $host === 'status.example.com'
                && $address === '2606:4700:4700::1111' && $port === 8443 && $timeout > 0 && $timeout <= 10000
        )->andReturn(new TlsCertificateInspection(
            validFrom: now()->getTimestamp() + $secondsUntilValid, validUntil: now()->getTimestamp() + $secondsUntilExpiry,
            fingerprint: str_repeat('a', 64), connectMs: 20.0
        ));
        Http::preventStrayRequests();

        $result = app(ProbeTlsMonitor::class)->probe(Monitor::factory()->tls()->state(['environment_id' => 1])->make(['tls_port' => 8443]));

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($reason, $result->reason);
        $this->assertSame((int) floor($secondsUntilExpiry / 86400), $result->details['days_remaining']);
        $this->assertSame(14, $result->details['expiry_days']);
        $this->assertSame(20.0, $result->connectMs);
        $this->assertNull($result->httpStatus);
        Http::assertNothingSent();
    }

    /** @return array<string, list<mixed>> */
    public static function expiryWindows(): array
    {
        return [
            'valid beyond threshold' => [30 * 86400, -86400, 'passed', 'up'],
            'exact threshold' => [14 * 86400, -86400, 'tls_expiring', 'down'],
            'one second above threshold' => [14 * 86400 + 1, -86400, 'passed', 'up'],
            'under one day' => [86399, -86400, 'tls_expiring', 'down'],
            'expiry instant' => [0, -86400, 'tls_expired', 'down'],
            'expired' => [-1, -86400, 'tls_expired', 'down'],
            'not yet valid' => [30 * 86400, 1, 'tls_not_yet_valid', 'down'],
        ];
    }

    #[DataProvider('transportFailures')]
    public function test_distinguishes_failed_tls_from_unavailable_checker_metadata(string $error, string $outcome): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        $this->mock(TlsCertificateInspector::class)->shouldReceive('inspect')->once()->andReturn(new TlsCertificateInspection(error: $error));

        $result = app(ProbeTlsMonitor::class)->probe(Monitor::factory()->tls()->state(['environment_id' => 1])->make());

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame($error, $result->reason);
        $this->assertSame([], $result->details);
    }

    /** @return array<string, list<mixed>> */
    public static function transportFailures(): array
    {
        return [
            'certificate or network failure' => ['tls_connection_failed', 'down'],
            'local checker failure' => ['checker_unavailable', 'unknown'],
            'no certificate metadata' => ['tls_certificate_unavailable', 'unknown'],
        ];
    }

    /**
     * @param  array<mixed>  $addresses
     */
    #[DataProvider('unsafeAnswers')]
    public function test_refuses_private_and_mixed_dns_answers_before_handshaking(array $addresses, string $reason): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->once()->andReturn($addresses);
        $this->mock(TlsCertificateInspector::class)->shouldNotReceive('inspect');

        $result = app(ProbeTlsMonitor::class)->probe(Monitor::factory()->tls()->state(['environment_id' => 1])->make());

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

    public function test_missing_certificate_fields_never_pass(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        $this->mock(TlsCertificateInspector::class)->shouldReceive('inspect')->once()->andReturn(new TlsCertificateInspection);

        $result = app(ProbeTlsMonitor::class)->probe(Monitor::factory()->tls()->state(['environment_id' => 1])->make());

        $this->assertSame('unknown', $result->outcome);
        $this->assertSame('tls_certificate_unavailable', $result->reason);
    }

    public function test_unexpected_transport_exceptions_never_expose_messages(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['1.1.1.1']);
        $this->mock(TlsCertificateInspector::class)->shouldReceive('inspect')->once()->andThrow(new RuntimeException('private TLS error'));

        $result = app(ProbeTlsMonitor::class)->probe(Monitor::factory()->tls()->state(['environment_id' => 1])->make());

        $this->assertSame('checker_unavailable', $result->reason);
        $this->assertStringNotContainsString('private TLS', (string) json_encode($result->toArray()));
    }

    public function test_rejects_stored_path_injection_before_resolving(): void
    {
        $this->mock(DnsResolver::class)->shouldNotReceive('addresses');
        $this->mock(TlsCertificateInspector::class)->shouldNotReceive('inspect');

        $result = app(ProbeTlsMonitor::class)->probe(Monitor::factory()->tls()->state(['environment_id' => 1])->make(['hostname' => 'example.com/private']));

        $this->assertSame('target_invalid', $result->reason);
    }

    #[DataProvider('unsafeTransportTargets')]
    public function test_native_transport_also_rejects_unpinned_or_private_addresses(string $hostname, string $address, int $port): void
    {
        $this->mock(DnsResolver::class)->shouldNotReceive('addresses');

        $result = app(NativeTlsCertificateInspector::class)->inspect($hostname, $address, $port, 1000);

        $this->assertSame('target_not_public', $result->error);
        $this->assertNull($result->validUntil);
    }

    /** @return array<string, list<mixed>> */
    public static function unsafeTransportTargets(): array
    {
        return [
            'private destination' => ['example.com', '127.0.0.1', 443],
            'unresolved address' => ['example.com', 'another.example.com', 443],
            'bad hostname' => ['user:password@example.com', '1.1.1.1', 443],
            'bad port' => ['example.com', '1.1.1.1', 0],
            'literal different address' => ['1.1.1.1', '8.8.8.8', 443],
            'private IPv6' => ['example.com', '::1', 443],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Data\Monitoring\TlsCertificateInspection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TlsCertificateInspectionTest extends TestCase
{
    use MonitoringHelpers;

    private const CERTIFICATE = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIFazCCA1OgAwIBAgIRAIIQz7DSQONZRGPgu2OCiwAwDQYJKoZIhvcNAQELBQAw
TzELMAkGA1UEBhMCVVMxKTAnBgNVBAoTIEludGVybmV0IFNlY3VyaXR5IFJlc2Vh
cmNoIEdyb3VwMRUwEwYDVQQDEwxJU1JHIFJvb3QgWDEwHhcNMTUwNjA0MTEwNDM4
WhcNMzUwNjA0MTEwNDM4WjBPMQswCQYDVQQGEwJVUzEpMCcGA1UEChMgSW50ZXJu
ZXQgU2VjdXJpdHkgUmVzZWFyY2ggR3JvdXAxFTATBgNVBAMTDElTUkcgUm9vdCBY
MTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBAK3oJHP0FDfzm54rVygc
h77ct984kIxuPOZXoHj3dcKi/vVqbvYATyjb3miGbESTtrFj/RQSa78f0uoxmyF+
0TM8ukj13Xnfs7j/EvEhmkvBioZxaUpmZmyPfjxwv60pIgbz5MDmgK7iS4+3mX6U
A5/TR5d8mUgjU+g4rk8Kb4Mu0UlXjIB0ttov0DiNewNwIRt18jA8+o+u3dpjq+sW
T8KOEUt+zwvo/7V3LvSye0rgTBIlDHCNAymg4VMk7BPZ7hm/ELNKjD+Jo2FR3qyH
B5T0Y3HsLuJvW5iB4YlcNHlsdu87kGJ55tukmi8mxdAQ4Q7e2RCOFvu396j3x+UC
B5iPNgiV5+I3lg02dZ77DnKxHZu8A/lJBdiB3QW0KtZB6awBdpUKD9jf1b0SHzUv
KBds0pjBqAlkd25HN7rOrFleaJ1/ctaJxQZBKT5ZPt0m9STJEadao0xAH0ahmbWn
OlFuhjuefXKnEgV4We0+UXgVCwOPjdAvBbI+e0ocS3MFEvzG6uBQE3xDk3SzynTn
jh8BCNAw1FtxNrQHusEwMFxIt4I7mKZ9YIqioymCzLq9gwQbooMDQaHWBfEbwrbw
qHyGO0aoSCqI3Haadr8faqU9GY/rOPNk3sgrDQoo//fb4hVC1CLQJ13hef4Y53CI
rU7m2Ys6xt0nUW7/vGT1M0NPAgMBAAGjQjBAMA4GA1UdDwEB/wQEAwIBBjAPBgNV
HRMBAf8EBTADAQH/MB0GA1UdDgQWBBR5tFnme7bl5AFzgAiIyBpY9umbbjANBgkq
hkiG9w0BAQsFAAOCAgEAVR9YqbyyqFDQDLHYGmkgJykIrGF1XIpu+ILlaS/V9lZL
ubhzEFnTIZd+50xx+7LSYK05qAvqFyFWhfFQDlnrzuBZ6brJFe+GnY+EgPbk6ZGQ
3BebYhtF8GaV0nxvwuo77x/Py9auJ/GpsMiu/X1+mvoiBOv/2X/qkSsisRcOj/KK
NFtY2PwByVS5uCbMiogziUwthDyC3+6WVwW6LLv3xLfHTjuCvjHIInNzktHCgKQ5
ORAzI4JMPJ+GslWYHb4phowim57iaztXOoJwTdwJx4nLCgdNbOhdjsnvzqvHu7Ur
TkXWStAmzOVyyghqpZXjFaH3pO3JLF+l+/+sKAIuvtd7u+Nxe5AW0wdeRlN8NwdC
jNPElpzVmbUq4JUagEiuTDkHzsxHpFKVK7q4+63SM1N95R1NbdWhscdCb+ZAJzVc
oyi3B43njTOQ5yOf+1CceWxG1bQVs5ZufpsMljq4Ui0/1lvh+wjChP4kqKOJ2qxq
4RgqsahDYVvTH9w7jXbyLeiNdd8XM2w9U/t7y0Ff/9yi0GE44Za4rF2LN9d11TPA
mRGunUHBcnWEvgJBQl9nJEiU0Zsnvgc/ubhPgXRR4Xq37Z0j4r7g1SgEEzwxA57d
emyPxgcYxn/eR44/KJ4EBs+lVDR3veyJm+kXQ99b21/+jh5Xos1AnX5iItreGCc=
-----END CERTIFICATE-----
PEM;

    public function test_extracts_dates_and_sha256_from_a_fixed_public_certificate_without_retaining_the_pem(): void
    {
        $result = TlsCertificateInspection::fromVerifiedPem(self::CERTIFICATE, 12.5);

        $this->assertNull($result->error);
        $this->assertSame(1433415878, $result->validFrom);
        $this->assertSame(2064567878, $result->validUntil);
        $this->assertSame('96bcec06264976f37460779acf28c5a7cfe8a3c0aae11a8ffcee05c0bddf08c6', $result->fingerprint);
        $this->assertSame(12.5, $result->connectMs);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', (string) json_encode($result));
    }

    #[DataProvider('curlFailures')]
    public function test_classifies_native_curl_failures_without_unsupported_constant_aliases(int $code, string $reason): void
    {
        $result = TlsCertificateInspection::fromCurlFailure($code);

        $this->assertSame($reason, $result->error);
        $this->assertNull($result->validUntil);
    }

    /** @return array<string, list<mixed>> */
    public static function curlFailures(): array
    {
        return [
            'connection refused' => [7, 'tls_connection_failed'], 'timeout' => [28, 'tls_connection_failed'],
            'TLS negotiation' => [35, 'tls_connection_failed'], 'empty reply' => [52, 'tls_connection_failed'],
            'send failure' => [55, 'tls_connection_failed'], 'receive failure' => [56, 'tls_connection_failed'],
            'certificate trust or hostname' => [60, 'tls_connection_failed'],
            'invalid local CA bundle' => [77, 'checker_unavailable'], 'invalid local option' => [48, 'checker_unavailable'],
        ];
    }

    #[DataProvider('invalidCertificates')]
    public function test_invalid_certificate_metadata_is_unknown(string $certificate): void
    {
        $result = TlsCertificateInspection::fromVerifiedPem($certificate);

        $this->assertSame('tls_certificate_unavailable', $result->error);
        $this->assertNull($result->validUntil);
    }

    /** @return array<string, list<mixed>> */
    public static function invalidCertificates(): array
    {
        return [
            'empty' => [''], 'file reference' => ['file:///etc/ssl/certs/ISRG_Root_X1.pem'],
            'invalid pem' => ["-----BEGIN CERTIFICATE-----\ninvalid\n-----END CERTIFICATE-----"],
            'oversized pem' => ['-----BEGIN CERTIFICATE-----'.str_repeat('a', 65536)],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

final readonly class TlsCertificateInspection
{
    /**
     * What a TLS check learned about a certificate, or why it couldn't learn it.
     *
     * @param  ?string  $error  A reason code when the certificate couldn't be read or verified.
     * @param  ?int  $validFrom  When the certificate becomes valid, as a Unix timestamp.
     * @param  ?int  $validUntil  When it expires, as a Unix timestamp.
     * @param  ?string  $fingerprint  Its SHA-256 fingerprint, so a replaced certificate can be told apart.
     * @param  ?float  $connectMs  How long the TLS connection took.
     */
    public function __construct(
        public ?string $error = null,
        public ?int $validFrom = null,
        public ?int $validUntil = null,
        public ?string $fingerprint = null,
        public ?float $connectMs = null,
    ) {}

    /**
     * The result for a failed connection: network and TLS errors mean the target failed, anything else means the checker
     * itself had a problem.
     *
     * @param  int  $errorCode
     * @return TlsCertificateInspection
     */
    public static function fromCurlFailure(int $errorCode): self
    {
        return new self(error: in_array($errorCode, [
            CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_SSL_CONNECT_ERROR,
            CURLE_SSL_CACERT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING,
        ], true) ? 'tls_connection_failed' : 'checker_unavailable');
    }

    /**
     * Extract metadata only after the transport has verified the certificate and hostname.
     *
     * @param  string  $pem
     * @param  float|null  $connectMs
     * @return TlsCertificateInspection
     */
    public static function fromVerifiedPem(string $pem, ?float $connectMs = null): self
    {
        if (strlen($pem) > 65536 || ! str_starts_with($pem, '-----BEGIN CERTIFICATE-----')) {
            return new self(error: 'tls_certificate_unavailable', connectMs: $connectMs);
        }
        $certificate = @openssl_x509_parse($pem);
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
        if (! is_array($certificate) || ! is_int($certificate['validFrom_time_t'] ?? null)
            || ! is_int($certificate['validTo_time_t'] ?? null) || ! is_string($fingerprint)) {
            return new self(error: 'tls_certificate_unavailable', connectMs: $connectMs);
        }

        return new self(validFrom: $certificate['validFrom_time_t'], validUntil: $certificate['validTo_time_t'],
            fingerprint: $fingerprint, connectMs: $connectMs);
    }
}

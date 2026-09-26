<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Contracts\TlsCertificateInspector;
use App\Modules\Monitor\Data\Telemetry\TlsCertificateInspection;
use Throwable;

final class NativeTlsCertificateInspector implements TlsCertificateInspector
{
    public function __construct(private readonly PublicHttpTarget $targets, private readonly PublicWebhookTarget $addresses) {}

    public function inspect(string $hostname, string $address, int $port, int $timeoutMilliseconds): TlsCertificateInspection
    {
        $authority = str_contains($hostname, ':') ? '['.$hostname.']' : $hostname;
        $url = 'https://'.$authority.':'.$port.'/';
        $target = $this->targets->parse($url);
        if ($target === null || $target['host'] !== $hostname || ! $this->addresses->isPublic($address)
            || ($target['literal'] && $target['host'] !== $address)) {
            return new TlsCertificateInspection(error: 'target_not_public');
        }
        $handle = null;
        try {
            $handle = curl_init($url);
            if ($handle === false) {
                return new TlsCertificateInspection(error: 'checker_unavailable');
            }
            $pinned = str_contains($address, ':') ? '['.$address.']' : $address;
            // A connection-only transfer performs TLS verification without sending HTTP or credentials.
            $configured = curl_setopt_array($handle, [
                CURLOPT_CONNECT_ONLY => true, CURLOPT_CERTINFO => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                CURLOPT_TIMEOUT_MS => max(1, min(20000, $timeoutMilliseconds)),
                CURLOPT_CONNECTTIMEOUT_MS => max(1, min(20000, $timeoutMilliseconds)),
            ] + ($target['literal'] ? [] : [CURLOPT_RESOLVE => [$hostname.':'.$port.':'.$pinned]]));
            if (! $configured) {
                return new TlsCertificateInspection(error: 'checker_unavailable');
            }
            if (curl_exec($handle) === false) {
                return TlsCertificateInspection::fromCurlFailure(curl_errno($handle));
            }
            $certificates = curl_getinfo($handle, CURLINFO_CERTINFO);
            $connectMs = curl_getinfo($handle, CURLINFO_APPCONNECT_TIME) * 1000;
            $pem = is_array($certificates) ? ($certificates[0]['Cert'] ?? null) : null;

            return is_string($pem) ? TlsCertificateInspection::fromVerifiedPem($pem, $connectMs)
                : new TlsCertificateInspection(error: 'tls_certificate_unavailable', connectMs: $connectMs);
        } catch (Throwable) {
            return new TlsCertificateInspection(error: 'checker_unavailable');
        } finally {
            if ($handle instanceof \CurlHandle) {
                curl_close($handle);
            }
        }
    }
}

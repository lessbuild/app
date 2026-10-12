<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\WebsiteDomain;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class CheckWebsiteDomain
{
    /**
     * Check a domain now: whether its DNS points at the website's server, and whether it serves a valid certificate
     * (and until when). Its status and the reason for any problem, in words someone can act on, are saved on it.
     *
     * @param  WebsiteDomain  $domain
     * @return WebsiteDomain
     */
    public function handle(WebsiteDomain $domain): WebsiteDomain
    {
        $expected = $domain->website->server?->public_ip;
        $addresses = $this->addresses($domain->hostname);
        $points = $expected !== null && in_array($expected, $addresses, true);
        $dnsProblem = match (true) {
            $points => null,
            $addresses === [] => __(':domain has no A record yet. Add one pointing at :ip at your DNS provider.', ['domain' => $domain->hostname, 'ip' => $expected ?? '—']),
            default => __(':domain points at :addresses, not this website’s server (:ip). Change its A record to :ip at your DNS provider.', ['domain' => $domain->hostname, 'addresses' => implode(', ', $addresses), 'ip' => $expected ?? '—']),
        };
        // A domain behind Cloudflare's CDN answers with Cloudflare's addresses, so DNS can't be judged by the address.
        $dns = $points || ($domain->cdn_proxied && $addresses !== []) ? 'active' : 'pending';

        try {
            $expires = $this->certificateExpiry($domain->hostname);
            $ssl = $expires->isPast() ? 'expired' : ($expires->lessThanOrEqualTo(now()->addDays((int) config('infrastructure.certificate_warning_days'))) ? 'expiring' : 'active');
            $domain->forceFill(['dns_status' => $dns, 'ssl_status' => $ssl, 'certificate_expires_at' => $expires, 'last_checked_at' => now(), 'last_error' => $dns === 'active' ? null : $dnsProblem])->save();
        } catch (Throwable) {
            $domain->forceFill([
                'dns_status' => $dns, 'ssl_status' => 'error', 'last_checked_at' => now(),
                'last_error' => $dns === 'active' ? __('DNS points here, but :domain doesn’t serve a valid certificate yet. It’s issued on the first visit once DNS has spread; check again in a few minutes.', ['domain' => $domain->hostname]) : $dnsProblem,
            ])->save();
        }

        return $domain;
    }

    /**
     * Look up a hostname's IPv4 and IPv6 addresses.
     *
     * @param  string  $hostname
     * @return list<string>
     */
    private function addresses(string $hostname): array
    {
        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, @dns_get_record($hostname, DNS_A | DNS_AAAA) ?: [])));
    }

    /**
     * Connect over TLS and read when the certificate the hostname serves expires; a missing or invalid one throws.
     *
     * @param  string  $hostname
     * @return CarbonImmutable
     */
    private function certificateExpiry(string $hostname): CarbonImmutable
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $hostname, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client('ssl://'.$hostname.':443', $code, $error, 8, STREAM_CLIENT_CONNECT, $context);
        if (! is_resource($socket)) {
            throw new RuntimeException('TLS connection failed.');
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');

        return is_array($certificate) && isset($certificate['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC((int) $certificate['validTo_time_t']) : throw new RuntimeException('No expiry.');
    }
}

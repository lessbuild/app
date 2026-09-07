<?php

namespace App\Console\Commands;

use App\Models\WebsiteDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class CheckDomainCertificatesCommand extends Command
{
    protected $signature = 'buildpusher:domains:check {--limit=100 : Maximum domains to inspect}';

    protected $description = 'Check managed DNS resolution and TLS certificate expiration';

    /**
     * Inspect a bounded batch of the least recently checked domains and persist their DNS and certificate status.
     *
     * @return int SUCCESS after processing the batch; individual domain errors are retained on their records.
     */
    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $domains = WebsiteDomain::query()->with('website.server')->oldest('last_checked_at')->limit($limit)->get();
        foreach ($domains as $domain) {
            $this->check($domain);
        }
        $this->info("Checked {$domains->count()} domains.");

        return self::SUCCESS;
    }

    /**
     * Resolve domain addresses against its server and inspect the verified TLS certificate; persist DNS/SSL observations and store certificate errors instead of rethrowing them.
     *
     * @param  WebsiteDomain  $domain  Domain whose expected server address and current certificate should be inspected.
     */
    private function check(WebsiteDomain $domain): void
    {
        $addresses = collect(dns_get_record($domain->hostname, DNS_A | DNS_AAAA) ?: [])
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()->values()->all();
        $expected = $domain->website->server?->public_ip;
        $dnsStatus = $expected && in_array($expected, $addresses, true) ? 'active' : 'pending';
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $domain->hostname,
            'SNI_enabled' => true,
        ]]);
        try {
            $socket = stream_socket_client('ssl://'.$domain->hostname.':443', $errorCode, $error, 8, STREAM_CLIENT_CONNECT, $context);
            if (! is_resource($socket)) {
                throw new \RuntimeException('TLS connection failed.');
            }
            $params = stream_context_get_params($socket);
            fclose($socket);
            $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            $expires = is_array($certificate) && isset($certificate['validTo_time_t'])
                ? Carbon::createFromTimestampUTC((int) $certificate['validTo_time_t'])
                : null;
            if (! $expires) {
                throw new \RuntimeException('TLS certificate expiration is unavailable.');
            }
            $warningAt = now()->addDays(max(1, (int) config('domains.certificate_warning_days', 21)));
            $domain->forceFill([
                'dns_status' => $dnsStatus,
                'ssl_status' => $expires->isPast() ? 'expired' : ($expires->lessThanOrEqualTo($warningAt) ? 'expiring' : 'active'),
                'certificate_expires_at' => $expires,
                'last_checked_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable) {
            $domain->forceFill([
                'dns_status' => $dnsStatus,
                'ssl_status' => 'error',
                'last_checked_at' => now(),
                'last_error' => 'TLS verification failed.',
            ])->save();
        }
    }
}

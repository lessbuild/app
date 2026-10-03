<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use App\Contracts\Security\DomainProbe;
use App\Contracts\Security\Scanner;
use App\Data\Security\Finding;
use App\Models\Domain;
use App\Models\Project;
use App\Models\Website;
use App\Models\WebsiteDomain;

/**
 * Checks a project's domains from the outside: HTTPS and its certificate, old TLS versions, redirects from HTTP,
 * security headers, email authentication (SPF and DMARC) and CNAMEs left pointing at services that could be taken over.
 */
final class DomainScanner implements Scanner
{
    /**
     * The most hostnames one scan checks.
     *
     * @var int
     */
    private const MAX_HOSTS = 50;

    /**
     * Hosting services where a CNAME to a deleted app can be claimed by someone else.
     *
     * @var list<string>
     */
    private const TAKEOVER_SUFFIXES = ['herokuapp.com', 'herokudns.com', 'github.io', 's3.amazonaws.com', 's3-website', 'azurewebsites.net', 'cloudapp.net', 'trafficmanager.net', 'blob.core.windows.net', 'netlify.app', 'vercel.app', 'pantheonsite.io', 'ghost.io', 'myshopify.com', 'surge.sh', 'bitbucket.io', 'fly.dev', 'webflow.io', 'readthedocs.io', 'wpengine.com'];

    /**
     * Create a new DomainScanner instance.
     *
     * @param  DomainProbe  $probe  Does the network look-ups.
     */
    public function __construct(private readonly DomainProbe $probe) {}

    /**
     * Get the scanner's kind.
     *
     * @return string
     */
    public function kind(): string
    {
        return 'domains';
    }

    /**
     * Get the scanner's name.
     *
     * @return string
     */
    public function label(): string
    {
        return (string) __('Domains, HTTPS and email');
    }

    /**
     * Every plan includes the domain check.
     *
     * @return string|null
     */
    public function flag(): ?string
    {
        return null;
    }

    /**
     * Check each of the project's hostnames (its claimed domains and its websites' domains, up to 50), and email
     * authentication on its claimed domains.
     *
     * @param  Project  $project
     * @return array<string, list<Finding>>
     */
    public function scan(Project $project): array
    {
        $claimed = Domain::query()->where('project_id', $project->id)->pluck('hostname')->map(fn (string $host): string => strtolower($host))->all();
        $websiteHosts = WebsiteDomain::query()->whereIn('website_id', Website::query()->whereIn('environment_id', $project->environments()->select('id'))->select('id'))
            ->where('is_temporary', false)->get(['hostname', 'type'])
            ->mapWithKeys(fn (WebsiteDomain $domain): array => [strtolower($domain->hostname) => $domain->type])->all();
        $hosts = array_slice(array_unique([...array_keys($websiteHosts), ...$claimed]), 0, self::MAX_HOSTS);

        $results = [];
        foreach ($hosts as $host) {
            $results["domain:{$host}"] = $this->checkHost($host, ($websiteHosts[$host] ?? null) === 'redirect');
        }
        foreach ($claimed as $domain) {
            $results["email:{$domain}"] = $this->checkEmail($domain);
        }

        return $results;
    }

    /**
     * Check one hostname: its CNAME, certificate, TLS versions, the redirect from HTTP and (unless it only redirects)
     * its security headers.
     *
     * @param  string  $host
     * @param  bool  $redirectOnly
     * @return list<Finding>
     */
    private function checkHost(string $host, bool $redirectOnly): array
    {
        $findings = [];
        $cname = $this->probe->cname($host);
        if ($cname !== null && $this->takeoverRisk($cname) && ! $this->probe->resolves($cname)) {
            $findings[] = new Finding('takeover', 'critical', (string) __(':host points at :target, which no longer exists', ['host' => $host, 'target' => $cname]),
                (string) __('Someone could create :target on that service and serve their own content on your domain.', ['target' => $cname]), $host,
                fix: (string) __('Remove the CNAME record for :host, or point it somewhere you control.', ['host' => $host]));

            return $findings;
        }

        $certificate = $this->probe->certificate($host);
        if ($certificate['expires_at'] === null) {
            $findings[] = new Finding('https-down', 'high', (string) __(':host doesn’t answer over HTTPS', ['host' => $host]), $certificate['error'], $host,
                fix: (string) __('Check the DNS record points at the right server and the website is running.'));

            return $findings;
        }
        $seconds = $certificate['expires_at'] - time();
        $days = (int) ceil($seconds / 86400);
        if (! $certificate['valid']) {
            $findings[] = new Finding('cert-untrusted', 'critical', (string) __('The certificate for :host isn’t trusted', ['host' => $host]), $certificate['error'], $host,
                fix: (string) __('Issue a certificate for this exact hostname from a trusted authority. BuildPusher websites get one automatically once DNS points at the server.'));
        }
        if ($seconds < 0) {
            $findings[] = new Finding('cert-expired', 'critical', (string) __('The certificate for :host has expired', ['host' => $host]), null, $host, fix: (string) __('Renew the certificate now.'));
        } elseif ($days < 21) {
            $findings[] = new Finding('cert-expiring', $days < 7 ? 'high' : 'medium', trans_choice('The certificate for :host expires in :count day|The certificate for :host expires in :count days', $days, ['host' => $host, 'count' => $days]),
                $certificate['issuer'] === null ? null : (string) __('Issued by :issuer.', ['issuer' => $certificate['issuer']]), $host,
                fix: (string) __('Automatic renewal usually happens 30 days before expiry; check it isn’t failing.'), data: ['expires_at' => $certificate['expires_at']]);
        }
        if ($this->probe->acceptsOldTls($host)) {
            $findings[] = new Finding('old-tls', 'medium', (string) __(':host still accepts TLS 1.0 or 1.1', ['host' => $host]), (string) __('These versions have known weaknesses and browsers no longer use them.'), $host,
                fix: (string) __('Allow TLS 1.2 and 1.3 only in the web server’s settings.'));
        }

        $http = $this->probe->fetch("http://{$host}/");
        if ($http !== null && ($http['status'] < 300 || $http['status'] >= 400 || ! str_starts_with(strtolower($http['headers']['location'] ?? ''), 'https://'))) {
            $findings[] = new Finding('no-https-redirect', 'medium', (string) __(':host doesn’t send HTTP visitors to HTTPS', ['host' => $host]), null, $host,
                fix: (string) __('Redirect every http:// request to https://. Caddy does this by default.'));
        }
        if ($redirectOnly) {
            return $findings;
        }

        $https = $this->probe->fetch("https://{$host}/");
        if ($https === null) {
            return $findings;
        }
        $headers = $https['headers'];
        if (! isset($headers['strict-transport-security'])) {
            $findings[] = new Finding('header-hsts', 'medium', (string) __(':host doesn’t send Strict-Transport-Security', ['host' => $host]), (string) __('Without it, a first visit can be downgraded to plain HTTP.'), $host,
                fix: (string) __('Send Strict-Transport-Security: max-age=31536000; includeSubDomains.'), url: 'https://developer.mozilla.org/docs/Web/HTTP/Headers/Strict-Transport-Security');
        }
        if (strtolower($headers['x-content-type-options'] ?? '') !== 'nosniff') {
            $findings[] = new Finding('header-nosniff', 'low', (string) __(':host doesn’t send X-Content-Type-Options: nosniff', ['host' => $host]), null, $host, fix: (string) __('Send X-Content-Type-Options: nosniff.'));
        }
        if (! isset($headers['x-frame-options']) && ! str_contains(strtolower($headers['content-security-policy'] ?? ''), 'frame-ancestors')) {
            $findings[] = new Finding('header-framing', 'low', (string) __(':host can be framed by other sites', ['host' => $host]), (string) __('That allows clickjacking.'), $host,
                fix: (string) __('Send X-Frame-Options: SAMEORIGIN, or a Content-Security-Policy with frame-ancestors.'));
        }
        if (! isset($headers['referrer-policy'])) {
            $findings[] = new Finding('header-referrer', 'low', (string) __(':host doesn’t send a Referrer-Policy', ['host' => $host]), null, $host, fix: (string) __('Send Referrer-Policy: strict-origin-when-cross-origin.'));
        }
        foreach (['server', 'x-powered-by'] as $name) {
            if (isset($headers[$name]) && preg_match('/\d+\.\d+/', $headers[$name]) === 1) {
                $findings[] = new Finding("version-{$name}", 'low', (string) __(':host reveals software versions (:header)', ['host' => $host, 'header' => $headers[$name]]), (string) __('Version numbers tell attackers which known flaws to try.'), $host,
                    fix: (string) __('Hide version numbers in the :header header.', ['header' => $name]));
            }
        }

        return $findings;
    }

    /**
     * Check a claimed domain's SPF and DMARC records.
     *
     * @param  string  $domain
     * @return list<Finding>
     */
    private function checkEmail(string $domain): array
    {
        $findings = [];
        $spf = array_values(array_filter($this->probe->txt($domain), fn (string $record): bool => str_starts_with(strtolower($record), 'v=spf1')));
        if ($spf === []) {
            $findings[] = new Finding('spf-missing', 'medium', (string) __(':domain has no SPF record', ['domain' => $domain]), (string) __('Anyone can send email that claims to come from it.'), $domain,
                fix: (string) __('Publish a TXT record listing the services that send your email, ending in ~all or -all. If the domain sends no email, use "v=spf1 -all".'));
        } elseif (preg_match('/[+?]all\b|\ball\s*$/i', $spf[0]) === 1 && preg_match('/[~-]all\b/i', $spf[0]) !== 1) {
            $findings[] = new Finding('spf-open', 'high', (string) __(':domain’s SPF record lets anyone send as it', ['domain' => $domain]), $spf[0], $domain, fix: (string) __('End the SPF record with ~all or -all.'));
        }
        $dmarc = array_values(array_filter($this->probe->txt("_dmarc.{$domain}"), fn (string $record): bool => str_starts_with(strtolower($record), 'v=dmarc1')));
        if ($dmarc === []) {
            $findings[] = new Finding('dmarc-missing', 'medium', (string) __(':domain has no DMARC policy', ['domain' => $domain]), (string) __('Receivers aren’t told what to do with email that fails SPF or DKIM.'), $domain,
                fix: (string) __('Publish a TXT record at _dmarc.:domain, starting with "v=DMARC1; p=quarantine".', ['domain' => $domain]));
        } elseif (preg_match('/\bp\s*=\s*none\b/i', $dmarc[0]) === 1) {
            $findings[] = new Finding('dmarc-none', 'low', (string) __(':domain’s DMARC policy only monitors (p=none)', ['domain' => $domain]), $dmarc[0], $domain,
                fix: (string) __('Once reports look clean, move to p=quarantine and then p=reject.'));
        }

        return $findings;
    }

    /**
     * Determine whether a CNAME target is on a service where abandoned names can be claimed.
     *
     * @param  string  $target
     * @return bool
     */
    private function takeoverRisk(string $target): bool
    {
        foreach (self::TAKEOVER_SUFFIXES as $suffix) {
            if (str_contains($target, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

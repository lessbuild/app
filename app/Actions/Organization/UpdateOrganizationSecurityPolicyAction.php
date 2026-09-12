<?php

namespace App\Actions\Organization;

use App\Models\Organization;
use App\Services\Entitlements;
use App\Support\IpRangeMatcher;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationSecurityPolicyAction
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly IpRangeMatcher $ranges,
    ) {}

    /**
     * Validate security invariants and persist the normalized workspace security policy.
     *
     * @param  array<string, mixed>  $attributes  Validated security settings.
     */
    public function handle(Organization $organization, array $attributes, string $ip): void
    {
        $allowedRanges = collect(preg_split('/[\s,]+/', (string) ($attributes['allowed_ip_ranges'] ?? '')) ?: [])->filter()->values();
        foreach ($allowedRanges as $range) {
            [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
            $packed = @inet_pton($network);
            $bits = $prefix === null ? ($packed === false ? -1 : strlen($packed) * 8) : filter_var($prefix, FILTER_VALIDATE_INT);
            if ($packed === false || $bits === false || $bits < 0 || $bits > strlen($packed) * 8) {
                throw ValidationException::withMessages(['allowed_ip_ranges' => __('Enter valid IPv4 or IPv6 addresses and CIDR ranges.')]);
            }
        }
        $allowedDomains = collect(preg_split('/[\s,]+/', Str::lower((string) ($attributes['allowed_email_domains'] ?? ''))) ?: [])->filter();
        if ($allowedDomains->contains(fn ($domain) => ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}\z/D', $domain))) {
            throw ValidationException::withMessages(['allowed_email_domains' => __('Enter valid email domains.')]);
        }
        if ($allowedRanges->isNotEmpty() && ! $allowedRanges->contains(fn (string $range): bool => $this->ranges->contains($range, $ip))) {
            throw ValidationException::withMessages(['allowed_ip_ranges' => __('Include your current IP address so you do not lock yourself out.')]);
        }

        $sso = $organization->sso_configuration ?? [];
        if (filled($attributes['sso_issuer'] ?? null)) {
            $sso['issuer'] = rtrim($attributes['sso_issuer'], '/');
        }
        if (filled($attributes['sso_client_id'] ?? null)) {
            $sso['client_id'] = $attributes['sso_client_id'];
        }
        if (filled($attributes['sso_client_secret'] ?? null)) {
            $sso['client_secret'] = $attributes['sso_client_secret'];
        }
        $ssoChanged = ($sso['issuer'] ?? null) !== data_get($organization->sso_configuration, 'issuer')
            || ($sso['client_id'] ?? null) !== data_get($organization->sso_configuration, 'client_id')
            || filled($attributes['sso_client_secret'] ?? null)
            || (bool) $attributes['sso_enforced'] !== (bool) $organization->sso_enforced;
        if ($ssoChanged) {
            $this->entitlements->enforce($organization, 'sso');
        }
        if ($attributes['sso_enforced'] && (! filled($sso['issuer'] ?? null) || ! filled($sso['client_id'] ?? null) || ! filled($sso['client_secret'] ?? null))) {
            throw ValidationException::withMessages(['sso_enforced' => __('Configure the issuer, client ID, and client secret before enforcing SSO.')]);
        }

        $organization->update([
            'allowed_ip_ranges' => $allowedRanges->all(), 'allowed_email_domains' => $allowedDomains->values()->all(),
            'require_two_factor' => (bool) $attributes['require_two_factor'], 'session_idle_minutes' => $attributes['session_idle_minutes'] ?? null,
            'sso_configuration' => $sso === [] ? null : $sso, 'sso_enforced' => (bool) $attributes['sso_enforced'],
        ]);
    }
}

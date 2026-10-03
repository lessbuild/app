<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Data\Accounts\AccountSecurityData;
use App\Support\IpRange;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountSecurityRequest extends FormRequest
{
    /**
     * Get the validation rules: domains and ranges one per line, an idle limit between 5 minutes and a week, and an
     * HTTPS issuer.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'require_two_factor' => ['boolean'],
            'allowed_email_domains' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                foreach ($this->lines('allowed_email_domains') as $domain) {
                    if (preg_match('/\A(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $domain) !== 1) {
                        $fail(__(':domain isn’t a domain name.', ['domain' => $domain]));
                    }
                }
            }],
            'allowed_ip_ranges' => ['nullable', 'string', 'max:4000', function (string $attribute, mixed $value, Closure $fail): void {
                foreach ($this->lines('allowed_ip_ranges') as $range) {
                    if (! IpRange::isValid($range)) {
                        $fail(__(':range isn’t an IP address or CIDR range.', ['range' => $range]));
                    }
                }
            }],
            'session_idle_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'sso_issuer' => ['nullable', 'url:https', 'max:500'],
            'sso_client_id' => ['nullable', 'string', 'max:255'],
            'sso_client_secret' => ['nullable', 'string', 'max:2000'],
            'sso_enforced' => ['boolean'],
        ];
    }

    /**
     * Build the settings to save; a blank secret keeps the saved one.
     *
     * @return AccountSecurityData
     */
    public function toData(): AccountSecurityData
    {
        $text = fn (string $key): ?string => filled($value = $this->string($key)->trim()->toString()) ? $value : null;

        return new AccountSecurityData(
            requireTwoFactor: $this->boolean('require_two_factor'),
            emailDomains: $this->lines('allowed_email_domains'),
            ipRanges: $this->lines('allowed_ip_ranges'),
            idleMinutes: $this->filled('session_idle_minutes') ? $this->integer('session_idle_minutes') : null,
            ssoIssuer: ($issuer = $text('sso_issuer')) !== null ? rtrim($issuer, '/') : null,
            ssoClientId: $text('sso_client_id'),
            ssoClientSecret: $text('sso_client_secret'),
            ssoEnforced: $this->boolean('sso_enforced'),
        );
    }

    /**
     * Split a text box into its unique, lowercased, non-empty lines (commas work too).
     *
     * @param  string  $key
     * @return list<string>
     */
    private function lines(string $key): array
    {
        $lines = preg_split('/[\s,]+/', mb_strtolower($this->string($key)->toString())) ?: [];

        return array_values(array_unique(array_filter($lines, fn (string $line): bool => $line !== '')));
    }
}

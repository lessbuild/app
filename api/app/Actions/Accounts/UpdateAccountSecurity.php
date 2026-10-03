<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Audit\RecordAuditEntry;
use App\Data\Accounts\AccountSecurityData;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\User;
use App\Support\IpRange;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves an account's security rules and single sign-on settings. Every rule is checked against the person saving it
 * first, so nobody can lock themselves (and so everyone) out: they must be on an allowed network, have an allowed
 * email address, use a second factor before requiring one, and have signed in through the provider before enforcing it.
 */
final class UpdateAccountSecurity
{
    /**
     * Create a new UpdateAccountSecurity instance.
     *
     * @param  RecordAuditEntry  $audit  Records what changed.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Save the rules, refusing any that would lock out the person saving them.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  AccountSecurityData  $data
     * @param  string  $ip  the address the change is made from
     * @param  bool  $ssoVerified  whether this session has signed in through the account's provider
     * @return void
     */
    public function handle(User $actor, Account $account, AccountSecurityData $data, string $ip, bool $ssoVerified): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        $errors = [];
        if ($data->ipRanges !== [] && ! IpRange::any($data->ipRanges, $ip)) {
            $errors['allowed_ip_ranges'] = __('Your address (:ip) isn’t in these ranges, so saving them would lock you out.', ['ip' => $ip]);
        }
        $domain = mb_strtolower((string) \Illuminate\Support\Str::afterLast($actor->email, '@'));
        if ($data->emailDomains !== [] && ! in_array($domain, $data->emailDomains, true)) {
            $errors['allowed_email_domains'] = __('Include your own domain (:domain), or you couldn’t sign in with single sign-on.', ['domain' => $domain]);
        }
        if ($data->requireTwoFactor && ! $actor->hasSecondFactor()) {
            $errors['require_two_factor'] = __('Turn on two-factor authentication or add a passkey for yourself first.');
        }
        $secret = $data->ssoClientSecret ?? $account->sso_client_secret;
        $ssoChanged = $data->ssoIssuer !== $account->sso_issuer || $data->ssoClientId !== $account->sso_client_id || $data->ssoClientSecret !== null;
        $configured = $account->sso_protocol === 'saml' ? $account->hasSso() : ! (blank($data->ssoIssuer) || blank($data->ssoClientId) || blank($secret));
        if ($data->ssoEnforced && ! $configured) {
            $errors['sso_enforced'] = __('Fill in the issuer, client ID and secret before requiring single sign-on.');
        } elseif ($data->ssoEnforced && ! $account->sso_enforced && ($ssoChanged || ! $ssoVerified)) {
            $errors['sso_enforced'] = __('Save the settings, then use Test single sign-on to sign in through your provider once, before requiring it.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = array_filter([
            $data->requireTwoFactor !== $account->require_two_factor ? ($data->requireTwoFactor ? 'two-factor required' : 'two-factor optional') : null,
            $data->emailDomains !== ($account->allowed_email_domains ?? []) ? 'email domains' : null,
            $data->ipRanges !== ($account->allowed_ip_ranges ?? []) ? 'IP ranges' : null,
            $data->idleMinutes !== $account->session_idle_minutes ? 'idle sign-out' : null,
            $ssoChanged ? 'single sign-on settings' : null,
            $data->ssoEnforced !== $account->sso_enforced ? ($data->ssoEnforced ? 'single sign-on required' : 'single sign-on optional') : null,
        ]);
        $account->forceFill([
            'require_two_factor' => $data->requireTwoFactor,
            'allowed_email_domains' => $data->emailDomains === [] ? null : $data->emailDomains,
            'allowed_ip_ranges' => $data->ipRanges === [] ? null : $data->ipRanges,
            'session_idle_minutes' => $data->idleMinutes,
            'sso_issuer' => $data->ssoIssuer,
            'sso_client_id' => $data->ssoClientId,
            'sso_client_secret' => $secret,
            'sso_enforced' => $data->ssoEnforced,
        ])->save();
        if ($changes !== []) {
            $this->audit->handle(AuditAction::SecurityRulesChanged, $actor, $account->id, ['changes' => implode(', ', $changes)]);
        }
    }
}

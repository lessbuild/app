<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\ProviderType;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use App\Support\Hostname;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveProvider
{
    /**
     * Create a new SaveProvider instance.
     *
     * Connects or changes a provider.
     *
     * @param  RecordAuditEntry  $audit  Records the change (never the token).
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Connect a provider, or change one. A new token, or a new type, resets the connection health. The type can't change while servers use it.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  array<string, mixed>  $data  validated by ProviderRequest
     * @param  Provider|null  $provider
     * @return Provider
     */
    public function handle(Account $account, User $actor, array $data, ?Provider $provider = null): Provider
    {
        return DB::transaction(function () use ($account, $actor, $data, $provider): Provider {
            Gate::forUser($actor)->authorize($provider === null ? 'create' : 'update', $provider ?? Provider::class);
            $isNew = $provider === null;
            $provider = $isNew ? new Provider : Provider::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($provider->id);
            $type = ProviderType::from((string) $data['type']);
            if (! $isNew && $provider->type !== $type && $provider->hasAttachedResources()) {
                throw ValidationException::withMessages(['type' => __('The type can’t change while servers use this provider.')]);
            }
            $token = is_string($data['token'] ?? null) ? trim($data['token']) : '';
            if ($isNew && $token === '') {
                throw ValidationException::withMessages(['token' => __('Enter the API token.')]);
            }
            if ($type === ProviderType::Lightsail && $token !== '' && preg_match('/\A[A-Z0-9]{16,128}:[A-Za-z0-9\/+=]{20,128}\z/', $token) !== 1) {
                throw ValidationException::withMessages(['token' => __('For AWS Lightsail, enter the access key ID and secret access key as ACCESS_KEY_ID:SECRET.')]);
            }
            $baseUrl = $type === ProviderType::GitLab ? self::baseUrl($data['base_url'] ?? null) : null;
            $credentialChanged = $isNew || $token !== '' || $provider->type !== $type || $provider->base_url !== $baseUrl;
            $description = trim((string) ($data['description'] ?? ''));

            $provider->forceFill([
                'account_id' => $account->id,
                'created_by' => $provider->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'description' => $description !== '' ? $description : null,
                'type' => $type,
                'base_url' => $baseUrl,
                'connection_monitoring_enabled' => (bool) ($data['connection_monitoring_enabled'] ?? true),
                'connection_check_interval_minutes' => (int) ($data['connection_check_interval_minutes'] ?? 1440),
                'connection_failure_threshold' => (int) ($data['connection_failure_threshold'] ?? 1),
            ]);
            if ($token !== '') {
                $provider->token = $token;
            }
            if ($credentialChanged) {
                $provider->forceFill(['connection_status' => 'unchecked', 'connection_checked_at' => null, 'connection_failure_count' => 0]);
            }
            $provider->save();
            $this->audit->handle($isNew ? AuditAction::ProviderCreated : AuditAction::ProviderUpdated, $actor, $account->id, [
                'provider' => $provider->name, 'type' => $type->label(), 'token_changed' => ! $isNew && $token !== '',
            ]);

            return $provider;
        }, attempts: 3);
    }

    /**
     * Normalise a self-hosted GitLab's address to `https://host` (with its path, if GitLab lives under one), or null
     * for gitlab.com. Only HTTPS on the standard port, with a real hostname, is accepted.
     *
     * @param  mixed  $input
     * @return string|null
     */
    private static function baseUrl(mixed $input): ?string
    {
        $url = trim(is_string($input) ? $input : '');
        if ($url === '' || in_array(rtrim(strtolower($url), '/'), ['https://gitlab.com', 'gitlab.com'], true)) {
            return null;
        }
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? Hostname::normalize((string) ($parts['host'] ?? '')) : null;
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === null || isset($parts['port']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['base_url' => __('Enter your GitLab’s HTTPS address, such as https://gitlab.example.com.')]);
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && preg_match('#\A(/[A-Za-z0-9._-]+)+\z#', $path) !== 1) {
            throw ValidationException::withMessages(['base_url' => __('Enter your GitLab’s HTTPS address, such as https://gitlab.example.com.')]);
        }

        return 'https://'.$host.$path;
    }
}

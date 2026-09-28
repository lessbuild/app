<?php

declare(strict_types=1);

namespace App\Queries\Users;

use App\Models\SignInEvent;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Queries\Audit\PersonalAuditTrailQuery;
use Laravel\Passkeys\Passkey;

/** Everything held about a person (not their accounts' project data), as plain arrays. Never includes secrets. */
final class PersonalDataExportQuery
{
    /**
     * Create a new PersonalDataExportQuery instance.
     *
     * Collects everything stored about a person for their data export.
     *
     * @param  PersonalAuditTrailQuery  $auditTrail  Their own activity.
     */
    public function __construct(private readonly PersonalAuditTrailQuery $auditTrail) {}

    /**
     * Collect everything stored about the person: their profile, memberships, connected providers, passkeys, API
     * tokens (never secrets), sign-ins and activity.
     *
     * @param  User  $user
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'has_password' => $user->password !== null,
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            ],
            'accounts' => $user->memberships()->with('account')->get()->map(fn ($membership): array => [
                'account_id' => $membership->account_id,
                'account_name' => $membership->account->name,
                'role' => $membership->role->value,
                'joined_at' => $membership->created_at?->toIso8601String(),
            ])->values()->all(),
            'connected_providers' => $user->socialIdentities()->get()->map(fn (SocialIdentity $identity): array => [
                'provider' => $identity->provider->value,
                'email' => $identity->email,
                'connected_at' => $identity->created_at?->toIso8601String(),
                'last_used_at' => $identity->last_used_at?->toIso8601String(),
            ])->values()->all(),
            'passkeys' => $user->passkeys()->get()->map(fn (Passkey $passkey): array => [
                'name' => $passkey->name,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ])->values()->all(),
            'api_tokens' => $user->tokens()->get()->map(fn ($token): array => [
                'name' => $token->name,
                'account_id' => $token->account_id,
                'scopes' => $token->abilities,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])->values()->all(),
            'sign_ins' => SignInEvent::query()->where('user_id', $user->id)->orderBy('created_at')->get()->map(fn (SignInEvent $event): array => [
                'at' => $event->created_at->toIso8601String(),
                'succeeded' => $event->succeeded,
                'method' => $event->method?->value,
                'two_factor' => $event->two_factor,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
            ])->values()->all(),
            'activity' => $this->auditTrail->handle($user),
        ];
    }
}

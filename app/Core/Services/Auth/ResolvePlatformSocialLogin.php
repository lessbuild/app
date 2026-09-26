<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Models\UserIdentity;
use App\Core\Models\Workspace;
use App\Core\Services\Workspaces\ResolveWorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Resolves Core social identities without linking an account from an email match alone. */
final class ResolvePlatformSocialLogin
{
    public function __construct(
        private readonly RegisterPlatformAccount $registration,
        private readonly ResolveWorkspaceInvitation $invitations,
    ) {}

    public function handle(
        string $provider,
        string $providerUserId,
        string $email,
        string $name,
        ?string $invitationToken = null,
    ): PlatformSocialLoginResolution {
        if (! in_array($provider, PlatformSocialProviders::keys(), true)) {
            return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::IDENTITY_DISABLED);
        }

        $providerUserId = trim($providerUserId);
        $email = Str::lower(trim($email));
        $name = trim($name);

        if ($providerUserId === '' || strlen($providerUserId) > 191 || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::IDENTITY_DISABLED);
        }

        return DB::connection('core')->transaction(function () use ($provider, $providerUserId, $email, $name, $invitationToken): PlatformSocialLoginResolution {
            $mutex = DB::connection('core')->table('platform_registration_mutexes')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($mutex === null) {
                throw new HttpException(503, 'The platform registration migration is required.');
            }

            $identity = UserIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if ($identity !== null) {
                $user = $identity->status === 'active'
                    ? PlatformUser::query()->whereKey($identity->user_id)->where('status', 'active')->first()
                    : null;

                return $user instanceof PlatformUser
                    ? new PlatformSocialLoginResolution(PlatformSocialLoginResolution::RESOLVED, $user)
                    : new PlatformSocialLoginResolution(PlatformSocialLoginResolution::IDENTITY_DISABLED);
            }

            if (PlatformUser::query()
                ->where(fn ($query) => $query
                    ->where('email_normalized', $email)
                    ->orWhereRaw('LOWER(email) = ?', [$email]))
                ->exists()) {
                return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::EMAIL_EXISTS);
            }

            $invitation = null;
            if ($invitationToken !== null) {
                $invitation = $this->invitations->findValid($invitationToken, lockForUpdate: true);
                if ($invitation === null) {
                    return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::INVITATION_INVALID);
                }

                if (! hash_equals((string) $invitation->email_normalized, $email)) {
                    return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::INVITATION_EMAIL_MISMATCH);
                }
            }

            if (! $this->registration->available($invitationToken)) {
                return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::REGISTRATION_CLOSED);
            }

            $user = PlatformUser::query()->create([
                'name' => Str::limit($name !== '' ? $name : Str::before($email, '@'), 120, ''),
                'email' => $email,
                'email_normalized' => $email,
                'email_verified_at' => now(),
                'password' => null,
                'password_set_at' => null,
                'auth_type' => $provider,
                'status' => 'active',
            ]);

            if ($invitation === null) {
                $workspaceName = Str::limit($user->name.' workspace', 120, '');
                $slug = Str::limit(Str::slug($workspaceName) ?: 'workspace', 100, '').'-'.Str::lower(Str::random(8));
                $workspace = Workspace::query()->create([
                    'owner_user_id' => $user->getKey(),
                    'name' => $workspaceName,
                    'slug' => $slug,
                    'status' => 'active',
                ]);

                $workspace->memberships()->create([
                    'user_id' => $user->getKey(),
                    'role' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            UserIdentity::query()->create([
                'user_id' => $user->getKey(),
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_email' => $email,
                'verified_at' => now(),
                'status' => 'active',
            ]);

            return new PlatformSocialLoginResolution(PlatformSocialLoginResolution::RESOLVED, $user);
        }, attempts: 3);
    }
}

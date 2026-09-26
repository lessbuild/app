<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\Workspaces\AcceptWorkspaceInvitation;
use App\Core\Services\Workspaces\ResolveWorkspaceInvitation;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RegisterPlatformAccount
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly ResolveWorkspaceInvitation $invitations,
        private readonly AcceptWorkspaceInvitation $acceptInvitation,
    ) {}

    public function available(?string $invitationToken = null): bool
    {
        abort_unless(
            Schema::connection('core')->hasTable('users'),
            503,
            'The Core identity migration is required.',
        );

        if (! Schema::connection('core')->hasTable('platform_registration_mutexes')) {
            return false;
        }

        if (is_string($invitationToken) && $this->invitations->findValid($invitationToken) !== null) {
            return true;
        }

        return (bool) config('lessbuild.registration.enabled')
            || ((bool) config('lessbuild.registration.allow_first_user') && ! PlatformUser::query()->exists());
    }

    public function handle(
        string $name,
        string $email,
        string $password,
        ?string $workspaceName,
        ?string $invitationToken = null,
    ): PlatformUser {
        $this->assertSchemaReady();

        return DB::connection('core')->transaction(function () use ($name, $email, $password, $workspaceName, $invitationToken): PlatformUser {
            $mutex = DB::connection('core')->table('platform_registration_mutexes')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($mutex === null) {
                throw new HttpException(503, 'The platform registration migration is required.');
            }

            $invitation = is_string($invitationToken) && $invitationToken !== ''
                ? $this->invitations->findValid($invitationToken, lockForUpdate: true)
                : null;

            if ($invitationToken !== null && $invitation === null) {
                abort(404);
            }

            if ($invitation === null && ! $this->available()) {
                throw ValidationException::withMessages([
                    'registration' => __('New account registration is currently closed.'),
                ]);
            }

            $normalizedEmail = Str::lower(trim($email));
            if ($invitation !== null && ! hash_equals((string) $invitation->email_normalized, $normalizedEmail)) {
                throw ValidationException::withMessages([
                    'email' => __('Use the email address that received this workspace invitation.'),
                ]);
            }

            $emailExists = PlatformUser::query()
                ->where(fn ($query) => $query
                    ->where('email_normalized', $normalizedEmail)
                    ->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]))
                ->exists();

            if ($emailExists) {
                throw ValidationException::withMessages([
                    'email' => __('An account with this email address already exists.'),
                ]);
            }

            $user = PlatformUser::query()->create([
                'name' => trim($name),
                'email' => trim($email),
                'email_normalized' => $normalizedEmail,
                'email_verified_at' => $invitation === null ? null : now(),
                'password' => $this->hasher->make($password),
                'password_set_at' => now(),
                'auth_type' => 'password',
                'status' => 'active',
            ]);

            if ($invitation !== null && is_string($invitationToken)) {
                $this->acceptInvitation->handle($user, $invitationToken);
            } else {
                if (! is_string($workspaceName) || trim($workspaceName) === '') {
                    throw ValidationException::withMessages([
                        'workspace_name' => __('Enter a name for your workspace.'),
                    ]);
                }

                $slug = Str::slug($workspaceName) ?: 'workspace';
                $slug = Str::limit($slug, 100, '').'-'.Str::lower(Str::random(8));
                $workspace = Workspace::query()->create([
                    'owner_user_id' => $user->getKey(),
                    'name' => trim($workspaceName),
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

            return $user;
        }, attempts: 3);
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::connection('core')->hasTable('users')
                && Schema::connection('core')->hasTable('platform_registration_mutexes'),
            503,
            'The platform registration migrations are required.',
        );
    }
}

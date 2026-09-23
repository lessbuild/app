<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RegisterPlatformAccount
{
    public function __construct(private readonly Hasher $hasher) {}

    public function available(): bool
    {
        abort_unless(
            Schema::connection('core')->hasTable('users'),
            503,
            'The Core identity migration is required.',
        );

        if (! Schema::connection('core')->hasTable('platform_registration_mutexes')) {
            return false;
        }

        return (bool) config('lessbuild.registration.enabled')
            || ((bool) config('lessbuild.registration.allow_first_user') && ! PlatformUser::query()->exists());
    }

    public function handle(string $name, string $email, string $password, string $workspaceName): PlatformUser
    {
        $this->assertSchemaReady();

        return DB::connection('core')->transaction(function () use ($name, $email, $password, $workspaceName): PlatformUser {
            $mutex = DB::connection('core')->table('platform_registration_mutexes')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($mutex === null) {
                throw new HttpException(503, 'The platform registration migration is required.');
            }

            if (! $this->available()) {
                throw ValidationException::withMessages([
                    'registration' => __('New account registration is currently closed.'),
                ]);
            }

            $normalizedEmail = Str::lower(trim($email));
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
                'password' => $this->hasher->make($password),
                'password_set_at' => now(),
                'auth_type' => 'password',
                'status' => 'active',
            ]);

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

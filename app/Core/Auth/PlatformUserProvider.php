<?php

namespace App\Core\Auth;

use App\Core\Models\PlatformUser;
use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

/**
 * Resolves active Core accounts using the normalized email key imported from
 * each product's legacy account store.
 */
final class PlatformUserProvider extends EloquentUserProvider implements UserProvider
{
    /**
     * @param  mixed  $identifier
     */
    public function retrieveById($identifier): ?PlatformUser
    {
        $model = $this->createModel();

        return $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier)
            ->where('status', 'active')
            ->first();
    }

    /**
     * @param  mixed  $identifier
     */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?PlatformUser
    {
        $user = $this->retrieveById($identifier);

        if ($user === null) {
            return null;
        }

        $rememberToken = $user->getRememberToken();

        return filled($rememberToken) && hash_equals($rememberToken, $token)
            ? $user
            : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?PlatformUser
    {
        $email = $credentials['email_normalized'] ?? $credentials['email'] ?? null;
        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail === null) {
            return null;
        }

        $emailAccountCount = $this->newModelQuery()
            ->where('email_normalized', $normalizedEmail)
            ->limit(2)
            ->count();

        if ($emailAccountCount !== 1) {
            return null;
        }

        unset($credentials['email'], $credentials['email_normalized']);

        $credentials = array_filter(
            $credentials,
            static fn (string $key): bool => ! str_contains($key, 'password'),
            ARRAY_FILTER_USE_KEY,
        );

        $query = $this->newModelQuery()
            ->where('email_normalized', $normalizedEmail)
            ->where('status', 'active');

        foreach ($credentials as $key => $value) {
            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if (! $user instanceof PlatformUser || $user->status !== 'active') {
            return false;
        }

        $password = $credentials['password'] ?? null;
        $hashedPassword = $user->getAuthPassword();

        return is_string($password)
            && is_string($hashedPassword)
            && $this->hasher->check($password, $hashedPassword);
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return $email === '' ? null : $email;
    }
}

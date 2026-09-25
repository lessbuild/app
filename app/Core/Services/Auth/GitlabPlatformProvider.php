<?php

namespace App\Core\Services\Auth;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\GitlabProvider;
use UnexpectedValueException;

/** Uses GitLab's current v4 profile endpoint and only accepts a confirmed account email. */
final class GitlabPlatformProvider extends GitlabProvider
{
    protected $scopes = ['read_user'];

    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get($this->host.'/api/v4/user', [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        $user = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($user)) {
            throw new UnexpectedValueException('GitLab returned an invalid user profile.');
        }

        $email = $user['email'] ?? null;
        $user['email'] = is_string($email)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && filled($user['confirmed_at'] ?? null)
                ? $email
                : null;

        return $user;
    }
}

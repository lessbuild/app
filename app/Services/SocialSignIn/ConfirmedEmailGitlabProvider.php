<?php

declare(strict_types=1);

namespace App\Services\SocialSignIn;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\GitlabProvider;
use UnexpectedValueException;

/** Uses GitLab's v4 profile endpoint and only passes the email on when GitLab has confirmed it. */
final class ConfirmedEmailGitlabProvider extends GitlabProvider
{
    /** @var list<string> */
    protected $scopes = ['read_user'];

    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->host.'/api/v4/user', [
            RequestOptions::HEADERS => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
        ]);

        $user = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($user)) {
            throw new UnexpectedValueException('GitLab returned an invalid user profile.');
        }

        $email = $user['email'] ?? null;
        $user['email'] = is_string($email) && filled($user['confirmed_at'] ?? null) ? $email : null;

        return $user;
    }
}

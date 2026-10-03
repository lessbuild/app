<?php

declare(strict_types=1);

namespace App\Data\Users;

final readonly class SignInOptions
{
    /**
     * Create a new SignInOptions instance.
     *
     * What the sign-in and sign-up pages offer.
     *
     * @param  bool  $registrationOpen  Whether anyone may sign up; otherwise only with an access invitation.
     * @param  string|null  $invitedEmail  The address an access invitation was for, when the page has a valid one.
     * @param  list<array{key: string, label: string, url: string}>  $socialProviders  Providers set up here, with where
     *                                                                                 to start signing in with each.
     * @param  string|null  $error  Why the last social or single sign-on round trip didn't work.
     * @param  string|null  $status  A message from the last step, such as being signed out after being idle.
     * @param  string|null  $turnstileSiteKey  Cloudflare Turnstile's site key, when sign-up asks for the bot check.
     */
    public function __construct(
        public bool $registrationOpen,
        public ?string $invitedEmail,
        public array $socialProviders,
        public ?string $error,
        public ?string $status,
        public ?string $turnstileSiteKey,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Queries\Users;

use App\Data\Users\SignInOptions;
use App\Enums\SocialProvider;
use App\Services\Identity\SignUpProtection;
use App\Services\SocialSignIn\SocialSignInGateway;
use App\Services\Users\Registration;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/** Reads what the sign-in and sign-up pages offer. */
final class SignInOptionsQuery
{
    /**
     * Create a new SignInOptionsQuery instance.
     *
     * @param  Registration  $registration  Whether sign-up is open, and access invitations.
     * @param  SocialSignInGateway  $gateway  Which social providers are set up.
     * @param  SignUpProtection  $protection  Whether sign-up asks for the bot check.
     */
    public function __construct(private readonly Registration $registration, private readonly SocialSignInGateway $gateway, private readonly SignUpProtection $protection) {}

    /**
     * Describe the options for the pages, with the invited address for a valid invitation and any message the last
     * round trip left in the session.
     *
     * @param  string|null  $invite
     * @param  Session  $session
     * @return SignInOptions
     */
    public function handle(?string $invite, Session $session): SignInOptions
    {
        $providers = [];
        foreach (SocialProvider::cases() as $provider) {
            if ($this->gateway->configured($provider)) {
                $providers[] = [
                    'key' => $provider->value,
                    'label' => $provider->label(),
                    'url' => route('social.redirect', ['provider' => $provider->value, ...($invite !== null ? ['invite' => $invite] : [])], false),
                ];
            }
        }
        $errors = $session->get('errors');
        $bag = $errors instanceof ViewErrorBag ? $errors->getBag('default') : new MessageBag;
        $status = $session->get('status');

        return new SignInOptions(
            $this->registration->isOpen(),
            $this->registration->invitation($invite)?->email,
            $providers,
            $bag->first('social') ?: $bag->first('email') ?: null,
            is_string($status) ? $status : null,
            $this->protection->turnstileEnabled() ? (string) config('services.turnstile.site_key') : null,
        );
    }
}

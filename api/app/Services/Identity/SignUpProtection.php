<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\RequestOrigin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Keeps bots and floods off sign-up: at most five new accounts an hour from one address, a honeypot field people
 * never see, and Cloudflare Turnstile when its keys are set.
 */
final class SignUpProtection
{
    /** How many sign-ups one address may make in an hour. */
    public const PER_HOUR = 5;

    /**
     * Create a new SignUpProtection instance.
     *
     * @param  RequestOrigin  $origin  The address the sign-up comes from.
     */
    public function __construct(private readonly RequestOrigin $origin) {}

    /**
     * Determine whether Turnstile is set up (both keys).
     *
     * @return bool
     */
    public function turnstileEnabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /**
     * Check a sign-up and count it against its address; throws a validation error when it looks automated or the
     * address has signed up too often.
     *
     * @param  array<string, mixed>  $input  the sign-up form
     * @return void
     *
     * @throws ValidationException
     */
    public function check(array $input): void
    {
        $ip = $this->origin->ipAddress() ?? 'unknown';
        if (filled($input['website'] ?? null)) {
            throw ValidationException::withMessages(['email' => __('We couldn’t create that account.')]);
        }
        $key = 'sign-up:'.hash('sha256', $ip);
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['email' => __('Too many accounts were created from here. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }
        if ($this->turnstileEnabled() && ! $this->passesTurnstile(is_string($input['cf-turnstile-response'] ?? null) ? $input['cf-turnstile-response'] : '', $ip)) {
            throw ValidationException::withMessages(['email' => __('Please complete the check that you’re a person, then try again.')]);
        }
        RateLimiter::hit($key, 3600);
    }

    /**
     * Ask Cloudflare whether the Turnstile token is good. Fails closed when Cloudflare can't be reached.
     *
     * @param  string  $token
     * @param  string  $ip
     * @return bool
     */
    private function passesTurnstile(string $token, string $ip): bool
    {
        if ($token === '') {
            return false;
        }
        try {
            return Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('services.turnstile.secret_key'), 'response' => $token, 'remoteip' => $ip,
            ])->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }
}

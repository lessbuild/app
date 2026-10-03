<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;

final class ApiTokenExpiring extends InboxNotification
{
    /**
     * Create a new ApiTokenExpiring instance.
     *
     * Warns a token's owner, a week ahead, that it's about to expire.
     *
     * @param  string  $accountId  The token's account.
     * @param  string  $tokenName  The token's name.
     * @param  CarbonInterface  $expiresAt  When it expires.
     */
    public function __construct(private readonly string $accountId, private readonly string $tokenName, private readonly CarbonInterface $expiresAt) {}

    /**
     * Get the headline: which token expires, and how soon.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('The API token “:name” expires :when', ['name' => $this->tokenName, 'when' => $this->expiresAt->diffForHumans()]);
    }

    /**
     * Say what to do before it does.
     *
     * @return string
     */
    protected function body(): string
    {
        return __('Create a replacement and update whatever uses it before then.');
    }

    /**
     * Get the API tokens page.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('account.api-tokens');
    }

    /**
     * Get the token's account.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->accountId;
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;

final class ApiTokenExpiring extends InboxNotification
{
    /**
     * Warns a token's owner, a week ahead, that it's about to expire.
     *
     * @param  string  $accountId  The token's account.
     * @param  string  $tokenName  The token's name.
     * @param  CarbonInterface  $expiresAt  When it expires.
     */
    public function __construct(private readonly string $accountId, private readonly string $tokenName, private readonly CarbonInterface $expiresAt) {}

    /**
     * Which token expires, and how soon.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('The API token “:name” expires :when', ['name' => $this->tokenName, 'when' => $this->expiresAt->diffForHumans()]);
    }

    /**
     * What to do before it does.
     *
     * @return string
     */
    protected function body(): string
    {
        return __('Create a replacement and update whatever uses it before then.');
    }

    /**
     * The API tokens page.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('account.api-tokens');
    }

    /**
     * The token's account.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->accountId;
    }
}

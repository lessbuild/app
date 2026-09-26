<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Notifications;

use Carbon\CarbonInterface;

final class ApiTokenExpiring extends InboxNotification
{
    public function __construct(private readonly string $accountId, private readonly string $tokenName, private readonly CarbonInterface $expiresAt) {}

    protected function title(): string
    {
        return __('The API token “:name” expires :when', ['name' => $this->tokenName, 'when' => $this->expiresAt->diffForHumans()]);
    }

    protected function body(): string
    {
        return __('Create a replacement and update whatever uses it before then.');
    }

    protected function url(): string
    {
        return route('account.api-tokens');
    }

    protected function accountId(): string
    {
        return $this->accountId;
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

final class RemovedFromAccount extends InboxNotification
{
    public function __construct(private readonly string $accountName, private readonly string $actorName) {}

    protected function title(): string
    {
        return __('You were removed from :account', ['account' => $this->accountName]);
    }

    protected function body(): string
    {
        return __(':name removed you. Ask them if you still need access.', ['name' => $this->actorName]);
    }

    protected function url(): string
    {
        return route('dashboard');
    }
}

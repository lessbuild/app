<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Notifications;

final class RoleChanged extends InboxNotification
{
    public function __construct(private readonly string $accountId, private readonly string $accountName, private readonly string $role, private readonly string $actorName) {}

    protected function title(): string
    {
        return __('You are now :role in :account', ['role' => $this->role, 'account' => $this->accountName]);
    }

    protected function body(): string
    {
        return __(':name changed your role.', ['name' => $this->actorName]);
    }

    protected function url(): string
    {
        return route('account.members');
    }

    protected function accountId(): string
    {
        return $this->accountId;
    }
}

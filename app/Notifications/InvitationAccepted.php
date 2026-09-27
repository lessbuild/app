<?php

declare(strict_types=1);

namespace App\Notifications;

final class InvitationAccepted extends InboxNotification
{
    public function __construct(private readonly string $accountId, private readonly string $accountName, private readonly string $memberName) {}

    protected function title(): string
    {
        return __(':name joined :account', ['name' => $this->memberName, 'account' => $this->accountName]);
    }

    protected function body(): string
    {
        return __('They accepted your invitation.');
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

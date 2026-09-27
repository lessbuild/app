<?php

declare(strict_types=1);

namespace App\Notifications;

final class RemovedFromAccount extends InboxNotification
{
    /**
     * Tells someone they were removed from an account. There's no account link, since they can't open it any more.
     *
     * @param  string  $accountName  The account's name.
     * @param  string  $actorName  Who removed them, so they know whom to ask.
     */
    public function __construct(private readonly string $accountName, private readonly string $actorName) {}

    /**
     * Which account they were removed from.
     */
    protected function title(): string
    {
        return __('You were removed from :account', ['account' => $this->accountName]);
    }

    /**
     * Who removed them.
     */
    protected function body(): string
    {
        return __(':name removed you. Ask them if you still need access.', ['name' => $this->actorName]);
    }

    /**
     * Their dashboard, since the account's pages are closed to them now.
     */
    protected function url(): string
    {
        return route('dashboard');
    }
}

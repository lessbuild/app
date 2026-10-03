<?php

declare(strict_types=1);

namespace App\Notifications;

final class RemovedFromAccount extends InboxNotification
{
    /**
     * Create a new RemovedFromAccount instance.
     *
     * Tells someone they were removed from an account. There's no account link, since they can't open it any more.
     *
     * @param  string  $accountName  The account's name.
     * @param  string  $actorName  Who removed them, so they know whom to ask.
     */
    public function __construct(private readonly string $accountName, private readonly string $actorName) {}

    /**
     * Get the headline, naming the account they were removed from.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('You were removed from :account', ['account' => $this->accountName]);
    }

    /**
     * Say who removed them.
     *
     * @return string
     */
    protected function body(): string
    {
        return __(':name removed you. Ask them if you still need access.', ['name' => $this->actorName]);
    }

    /**
     * Get the person's dashboard address, since the account's pages are closed to them now.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('dashboard');
    }
}

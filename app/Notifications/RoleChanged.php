<?php

declare(strict_types=1);

namespace App\Notifications;

final class RoleChanged extends InboxNotification
{
    /**
     * Tells a member their role in an account changed.
     *
     * @param  string  $accountId  The account, for the inbox.
     * @param  string  $accountName  The account's name, captured when it happened.
     * @param  string  $role  The new role's label.
     * @param  string  $actorName  Who changed it.
     */
    public function __construct(private readonly string $accountId, private readonly string $accountName, private readonly string $role, private readonly string $actorName) {}

    /**
     * "You are now … in …".
     */
    protected function title(): string
    {
        return __('You are now :role in :account', ['role' => $this->role, 'account' => $this->accountName]);
    }

    /**
     * Who made the change.
     */
    protected function body(): string
    {
        return __(':name changed your role.', ['name' => $this->actorName]);
    }

    /**
     * The members page, where they can see their role.
     */
    protected function url(): string
    {
        return route('account.members');
    }

    /**
     * The account the role is in.
     */
    protected function accountId(): string
    {
        return $this->accountId;
    }
}

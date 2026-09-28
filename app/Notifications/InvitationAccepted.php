<?php

declare(strict_types=1);

namespace App\Notifications;

final class InvitationAccepted extends InboxNotification
{
    /**
     * Tells whoever sent an invitation that it was accepted.
     *
     * @param  string  $accountId  The account they joined.
     * @param  string  $accountName  The account's name.
     * @param  string  $memberName  Who joined.
     */
    public function __construct(private readonly string $accountId, private readonly string $accountName, private readonly string $memberName) {}

    /**
     * Who joined which account.
     *
     * @return string
     */
    protected function title(): string
    {
        return __(':name joined :account', ['name' => $this->memberName, 'account' => $this->accountName]);
    }

    /**
     * That it was their invitation.
     *
     * @return string
     */
    protected function body(): string
    {
        return __('They accepted your invitation.');
    }

    /**
     * The members page.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('account.members');
    }

    /**
     * The account that gained a member.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->accountId;
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Provider;

/** A provider's credential stopped working, or works again. */
final class ProviderConnectionChanged extends InboxNotification
{
    public function __construct(private readonly Provider $provider, private readonly bool $failed, private readonly string $detail) {}

    protected function title(): string
    {
        return $this->failed
            ? __('The provider “:provider” can’t connect', ['provider' => $this->provider->name])
            : __('The provider “:provider” connects again', ['provider' => $this->provider->name]);
    }

    protected function body(): string
    {
        return $this->failed ? $this->detail : __(':type accepted the stored credential again.', ['type' => $this->provider->type->label()]);
    }

    protected function url(): string
    {
        return route('account.providers.show', $this->provider->id);
    }

    protected function accountId(): string
    {
        return $this->provider->account_id;
    }
}

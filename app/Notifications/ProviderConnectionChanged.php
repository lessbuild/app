<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Provider;

/** A provider's credential stopped working, or works again. */
final class ProviderConnectionChanged extends InboxNotification
{
    /**
     * Create a new ProviderConnectionChanged instance.
     *
     * Tells whoever connected a cloud or source-control provider that it stopped accepting the stored credential, or
     * accepts it again.
     *
     * @param  Provider  $provider  The provider.
     * @param  bool  $failed  True when the connection broke; false when it recovered.
     * @param  string  $detail  What the provider said, shown when it broke.
     */
    public function __construct(private readonly Provider $provider, private readonly bool $failed, private readonly string $detail) {}

    /**
     * Get the headline, naming the provider and whether it connects.
     *
     * @return string
     */
    protected function title(): string
    {
        return $this->failed
            ? __('The provider “:provider” can’t connect', ['provider' => $this->provider->name])
            : __('The provider “:provider” connects again', ['provider' => $this->provider->name]);
    }

    /**
     * Give the provider's error, or say it accepts the credential again.
     *
     * @return string
     */
    protected function body(): string
    {
        return $this->failed ? $this->detail : __(':type accepted the stored credential again.', ['type' => $this->provider->type->label()]);
    }

    /**
     * Get the provider's page, where the credential can be replaced.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('account.providers.show', $this->provider->id);
    }

    /**
     * Get the provider's account.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->provider->account_id;
    }
}

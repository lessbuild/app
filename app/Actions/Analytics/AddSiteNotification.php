<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\AlertDestinationType;
use App\Exceptions\AccountRuleViolation;
use App\Models\AnalyticsNotification;
use App\Models\AnalyticsSite;
use App\Models\User;
use App\Services\Monitoring\PublicWebhookTarget;
use Illuminate\Support\Facades\Gate;

final class AddSiteNotification
{
    /**
     * How many reports and alerts one site can have.
     *
     * @var int
     */
    public const MAX_PER_SITE = 10;

    /**
     * Create a new AddSiteNotification instance.
     *
     * @param  PublicWebhookTarget  $webhooks  Checks Slack webhook addresses.
     */
    public function __construct(private readonly PublicWebhookTarget $webhooks) {}

    /**
     * Add a weekly or monthly report, or a traffic spike alert (with the number of current visitors that counts as a
     * spike), sent to an email address or a Slack incoming webhook.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $kind  one of AnalyticsNotification::KINDS
     * @param  string  $channel  email or slack
     * @param  string  $target  the email address or Slack webhook address
     * @param  int|null  $threshold  current visitors for a spike alert
     * @return AnalyticsNotification
     */
    public function handle(User $actor, AnalyticsSite $site, string $kind, string $channel, string $target, ?int $threshold): AnalyticsNotification
    {
        Gate::forUser($actor)->authorize('update', $site);
        if ($site->notifications()->count() >= self::MAX_PER_SITE) {
            throw new AccountRuleViolation('target', __('A site can have up to :count reports and alerts.', ['count' => self::MAX_PER_SITE]));
        }
        $target = trim($target);
        if ($channel === 'slack' && $this->webhooks->host($target, AlertDestinationType::Slack) === null) {
            throw new AccountRuleViolation('target', __('Paste a Slack incoming webhook address (https://hooks.slack.com/services/…).'));
        }
        if ($channel === 'email' && filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountRuleViolation('target', __('Enter an email address.'));
        }
        if ($kind === 'spike' && ($threshold === null || $threshold < 1)) {
            throw new AccountRuleViolation('threshold', __('Say how many current visitors count as a spike.'));
        }

        $notification = new AnalyticsNotification;
        $notification->forceFill([
            'site_id' => $site->id, 'created_by' => $actor->id, 'kind' => $kind, 'channel' => $channel, 'target' => $target,
            'threshold' => $kind === 'spike' ? $threshold : null,
        ])->save();

        return $notification;
    }
}

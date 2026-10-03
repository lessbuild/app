<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Exceptions\AccountRuleViolation;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsSiteViewer;
use App\Models\User;
use App\Notifications\SiteViewerInvitation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class InviteSiteViewer
{
    /**
     * How many view-only people one site can have.
     *
     * @var int
     */
    public const MAX_PER_SITE = 25;

    /**
     * Give someone view-only access to one site's report and email them their personal link. Inviting the same
     * address again sends a new link and retires the old one.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $email
     * @return AnalyticsSiteViewer
     */
    public function handle(User $actor, AnalyticsSite $site, string $email): AnalyticsSiteViewer
    {
        Gate::forUser($actor)->authorize('update', $site);
        $email = strtolower(trim($email));
        $viewer = AnalyticsSiteViewer::query()->where('site_id', $site->id)->where('email', $email)->first();
        if ($viewer === null && AnalyticsSiteViewer::query()->where('site_id', $site->id)->count() >= self::MAX_PER_SITE) {
            throw new AccountRuleViolation('email', __('A site can have up to :count view-only people.', ['count' => self::MAX_PER_SITE]));
        }
        $token = Str::random(40);
        $viewer ??= new AnalyticsSiteViewer;
        $viewer->forceFill(['site_id' => $site->id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'invited_by' => $actor->id])->save();
        Notification::route('mail', $email)->notify(new SiteViewerInvitation($site->name, $actor->name, route('analytics.viewer', $token)));

        return $viewer;
    }
}

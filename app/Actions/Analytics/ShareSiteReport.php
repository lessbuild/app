<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ShareSiteReport
{
    /**
     * Create a new ShareSiteReport instance.
     *
     * @param  RecordAuditEntry  $audit  Records who shared it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Share a site's report by a secret link (read-only, no account needed), optionally behind a password. Keeps the
     * existing link unless a new one is asked for, so links already handed out keep working.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string|null  $password  null or empty for no password
     * @param  bool  $newLink  replace the link, so the old one stops working
     * @return AnalyticsSite
     */
    public function handle(User $actor, AnalyticsSite $site, ?string $password, bool $newLink = false): AnalyticsSite
    {
        Gate::forUser($actor)->authorize('update', $site);

        $site->forceFill([
            'share_token' => $newLink || $site->share_token === null ? Str::random(40) : $site->share_token,
            'share_password' => $password !== null && $password !== '' ? Hash::make($password) : null,
            'shared_at' => now(),
        ])->save();
        $this->audit->handle(AuditAction::AnalyticsReportShared, $actor, $site->project->account_id, [
            'site' => $site->name, 'protected' => $site->share_password !== null,
        ], $site->project_id);

        return $site;
    }
}

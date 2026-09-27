<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Enums\AccountRole;
use App\Enums\IssueStatus;
use App\Models\Account;
use App\Models\Issue;
use App\Models\IssueDigestDelivery;
use App\Models\IssueDigestPreference;
use App\Models\User;
use App\Notifications\IssueDigestNotification;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\EmailDeliveryLedger;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The daily issue digest: new and resolved issues across an account's projects, and how many are still open.
 * Owners get it unless they turn it off; other members who can use Monitoring can turn it on.
 *
 * @phpstan-type DigestIssue array{title: string, location: string|null, project: string, severity: string, occurrences: int, at: string, url: string}
 * @phpstan-type Digest array{account: string, from: CarbonImmutable, until: CarbonImmutable, open: int, critical: int, snoozed: int, new: list<DigestIssue>, resolved: list<DigestIssue>, active: bool}
 */
final class IssueDigest
{
    public function __construct(private readonly TelemetryRedactor $redactor, private readonly EmailDeliveryLedger $ledger, private readonly Entitlements $entitlements) {}

    /** @return Digest */
    public function report(Account $account, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $issues = fn () => Issue::query()->whereIn('project_id', $account->projects()->select('id'));
        $open = $issues()->where('status', IssueStatus::Open)->count();
        $snoozed = $issues()->where('status', IssueStatus::Snoozed)->count();
        $new = $this->issues($issues()->where('first_seen_at', '>=', $from->format('Y-m-d H:i:s.u'))->where('first_seen_at', '<', $until->format('Y-m-d H:i:s.u')), 'first_seen_at');
        $resolved = $this->issues($issues()->where('resolved_at', '>=', $from->format('Y-m-d H:i:s.u'))->where('resolved_at', '<', $until->format('Y-m-d H:i:s.u')), 'resolved_at');

        return [
            'account' => $account->name,
            'from' => $from,
            'until' => $until,
            'open' => $open,
            'critical' => $issues()->where('status', IssueStatus::Open)->where('severity', 'critical')->count(),
            'snoozed' => $snoozed,
            'new' => $new,
            'resolved' => $resolved,
            'active' => $new !== [] || $resolved !== [] || $open > 0 || $snoozed > 0,
        ];
    }

    /** @return array{sent: int, skipped: int, failed: int} */
    public function send(CarbonImmutable $from, CarbonImmutable $until, ?string $accountId = null): array
    {
        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $accounts = Account::query()->when($accountId !== null, fn ($query) => $query->whereKey($accountId))->with('memberships.user');
        foreach ($accounts->lazyById(100) as $account) {
            if (! $this->entitlements->for($account)->has('monitoring.issue_digest')) {
                continue;
            }
            $recipients = $this->recipients($account);
            if ($recipients === []) {
                continue;
            }
            $digest = $this->report($account, $from, $until);
            if (! $digest['active']) {
                continue;
            }
            foreach ($recipients as $recipient) {
                $totals[$this->ledger->send(IssueDigestDelivery::class, [
                    'account_id' => $account->id, 'recipient_id' => $recipient->id,
                    'period_start' => $from->format('Y-m-d H:i:s.u'), 'period_end' => $until->format('Y-m-d H:i:s.u'),
                ], ['new_count' => count($digest['new']), 'resolved_count' => count($digest['resolved']), 'open_count' => $digest['open']],
                    fn () => $recipient->notifyNow(new IssueDigestNotification($digest)))]++;
            }
        }

        return $totals;
    }

    /** Whether a member gets the digest: their own choice, or by default only owners. */
    public function wants(Account $account, User $user): bool
    {
        $preference = IssueDigestPreference::query()->where('account_id', $account->id)->where('user_id', $user->id)->value('enabled');

        return $preference === null ? $account->roleOf($user) === AccountRole::Owner : (bool) $preference;
    }

    /** @return list<User> */
    private function recipients(Account $account): array
    {
        $preferences = IssueDigestPreference::query()->where('account_id', $account->id)->pluck('enabled', 'user_id');
        $recipients = [];
        foreach ($account->memberships as $membership) {
            $wants = $preferences->has($membership->user_id) ? (bool) $preferences->get($membership->user_id) : $membership->role === AccountRole::Owner;
            if ($wants && $membership->role !== AccountRole::Viewer && $membership->canUseService('monitoring') && $membership->user->email_verified_at !== null) {
                $recipients[] = $membership->user;
            }
        }

        return $recipients;
    }

    /**
     * The ten latest, with titles redacted.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Issue>  $query
     * @return list<DigestIssue>
     */
    private function issues(\Illuminate\Database\Eloquent\Builder $query, string $column): array
    {
        $rows = [];
        foreach ($query->with('project')->orderByDesc($column)->orderByDesc('id')->limit(10)->get() as $issue) {
            $safe = $this->redactor->redact(['title' => $issue->title, 'location' => $issue->location]);
            $at = $issue->{$column};
            $rows[] = [
                'title' => (string) $safe['title'],
                'location' => is_string($safe['location'] ?? null) ? $safe['location'] : null,
                'project' => $issue->project->name,
                'severity' => $issue->severity,
                'occurrences' => $issue->occurrences,
                'at' => $at instanceof DateTimeInterface ? CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i').' UTC' : '',
                'url' => route('monitoring.issues.show', [$issue->project_id, $issue->id]),
            ];
        }

        return $rows;
    }
}

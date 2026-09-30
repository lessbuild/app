<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Services\Monitoring\IssueTrackerClient;

final class SyncIssueTickets
{
    /**
     * Create a new SyncIssueTickets instance.
     *
     * @param  IssueTrackerClient  $client  Asks each tracker about its tickets.
     */
    public function __construct(private readonly IssueTrackerClient $client) {}

    /**
     * Resolve open or snoozed issues whose ticket has been finished in its tracker, noting it on the issue. A new
     * occurrence reopens them as usual. Returns how many were resolved.
     *
     * @return int
     */
    public function handle(): int
    {
        $resolved = 0;
        Issue::query()->whereNotNull('ticket_tracker_id')->whereIn('status', [IssueStatus::Open, IssueStatus::Snoozed])
            ->with('ticketTracker')->orderBy('id')->limit(500)->get()
            ->each(function (Issue $issue) use (&$resolved): void {
                if ($issue->ticketTracker === null || $issue->ticket_key === null || $this->client->isDone($issue->ticketTracker, $issue->ticket_key) !== true) {
                    return;
                }
                $issue->forceFill(['status' => IssueStatus::Resolved, 'resolved_at' => now('UTC'), 'snoozed_until' => null, 'state_version' => $issue->state_version + 1])->save();
                (new IssueActivity)->forceFill(['issue_id' => $issue->id, 'action' => 'ticket_closed', 'metadata' => ['key' => $issue->ticket_key]])->save();
                $resolved++;
            });

        return $resolved;
    }
}

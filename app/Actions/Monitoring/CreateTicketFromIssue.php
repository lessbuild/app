<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Exceptions\AccountRuleViolation;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueTracker;
use App\Models\User;
use App\Services\Monitoring\IssueTrackerClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class CreateTicketFromIssue
{
    /**
     * Create a new CreateTicketFromIssue instance.
     *
     * @param  IssueTrackerClient  $client  Files the ticket.
     */
    public function __construct(private readonly IssueTrackerClient $client) {}

    /**
     * File a ticket for a Monitoring issue in one of the project's trackers, with the error, where it happened, how
     * often, and a link back; then link the issue to the ticket and note it in the activity. An issue gets one ticket.
     *
     * @param  User  $actor
     * @param  Issue  $issue
     * @param  IssueTracker  $tracker
     * @return string the ticket's address
     */
    public function handle(User $actor, Issue $issue, IssueTracker $tracker): string
    {
        Gate::forUser($actor)->authorize('manageService', [$issue->project, 'monitoring']);
        if ($tracker->project_id !== $issue->project_id) {
            throw new AccountRuleViolation('tracker', __('Choose one of this project’s trackers.'));
        }
        if ($issue->ticket_url !== null) {
            throw new AccountRuleViolation('tracker', __('This issue already has a ticket: :key.', ['key' => $issue->ticket_key]));
        }
        $body = implode("\n\n", array_filter([
            $issue->title,
            $issue->location !== null ? __('Where: :location', ['location' => $issue->location]) : null,
            __(':count occurrences, :users people affected. First seen :first, last seen :last (UTC).', ['count' => number_format($issue->occurrences), 'users' => number_format($issue->affected_users), 'first' => $issue->first_seen_at->utc()->format('Y-m-d H:i'), 'last' => $issue->last_seen_at->utc()->format('Y-m-d H:i')]),
            $issue->details !== null ? Str::limit($issue->details, 3000) : null,
            __('Filed from BuildPusher Monitoring: :url', ['url' => route('monitoring.issues.show', [$issue->project_id, $issue->id])]),
        ]));
        try {
            $ticket = $this->client->create($tracker, Str::limit($issue->title, 200, '…'), $body);
        } catch (Throwable $exception) {
            throw new AccountRuleViolation('tracker', __('The ticket wasn’t created. :reason', ['reason' => Str::limit($exception->getMessage(), 300)]));
        }
        $issue->forceFill(['ticket_key' => mb_substr($ticket['key'], 0, 100), 'ticket_url' => mb_substr($ticket['url'], 0, 500), 'ticket_tracker_id' => $tracker->id])->save();
        (new IssueActivity)->forceFill(['issue_id' => $issue->id, 'actor_id' => $actor->id, 'action' => 'ticket_created', 'metadata' => ['tracker' => $tracker->name, 'key' => $ticket['key'], 'url' => $ticket['url']]])->save();

        return $ticket['url'];
    }
}

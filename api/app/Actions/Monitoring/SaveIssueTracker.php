<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Exceptions\AccountRuleViolation;
use App\Models\IssueTracker;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SaveIssueTracker
{
    /**
     * How many trackers one project can have.
     *
     * @var int
     */
    public const MAX_PER_PROJECT = 5;

    /**
     * Connect a ticket tracker to the project: a GitHub repository (owner/name and a token that can write issues), a
     * Linear team (an API key and the team's ID) or a Jira Cloud project (the https://….atlassian.net site, an email,
     * an API token and the project key).
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $kind  github, linear or jira
     * @param  string  $name
     * @param  array<string, string|null>  $settings
     * @return IssueTracker
     */
    public function handle(User $actor, Project $project, string $kind, string $name, array $settings): IssueTracker
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'monitoring']);
        if (IssueTracker::query()->where('project_id', $project->id)->count() >= self::MAX_PER_PROJECT) {
            throw new AccountRuleViolation('kind', __('A project can have up to :count trackers.', ['count' => self::MAX_PER_PROJECT]));
        }
        $value = fn (string $key): string => trim((string) ($settings[$key] ?? ''));
        $clean = match ($kind) {
            'github' => ['repository' => $value('repository'), 'token' => $value('token')],
            'linear' => ['api_key' => $value('api_key'), 'team_id' => $value('team_id')],
            'jira' => ['site' => rtrim(mb_strtolower($value('site')), '/'), 'email' => $value('email'), 'token' => $value('token'), 'project_key' => strtoupper($value('project_key'))],
            default => throw new AccountRuleViolation('kind', __('Choose GitHub Issues, Linear or Jira.')),
        };
        if (in_array('', $clean, true)) {
            throw new AccountRuleViolation('kind', __('Fill in every field for :tracker.', ['tracker' => IssueTracker::KINDS[$kind]]));
        }
        if ($kind === 'github' && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value('repository')) !== 1) {
            throw new AccountRuleViolation('repository', __('Write the repository as owner/name.'));
        }
        if ($kind === 'jira' && (preg_match('#^https://[a-z0-9-]+\.atlassian\.net$#', rtrim(mb_strtolower($value('site')), '/')) !== 1 || preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', strtoupper($value('project_key'))) !== 1)) {
            throw new AccountRuleViolation('site', __('Use your Jira Cloud address (https://yours.atlassian.net) and a project key such as OPS.'));
        }
        $tracker = new IssueTracker;
        $tracker->forceFill(['project_id' => $project->id, 'created_by' => $actor->id, 'kind' => $kind, 'name' => mb_substr(trim($name) ?: IssueTracker::KINDS[$kind], 0, 80), 'settings' => $clean])->save();

        return $tracker;
    }
}

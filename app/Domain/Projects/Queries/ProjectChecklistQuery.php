<?php

declare(strict_types=1);

namespace App\Domain\Projects\Queries;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Data\ChecklistStep;
use App\Domain\Projects\Models\Project;

/** The getting-started steps on a new project's overview (journey 1 in docs/phase-2-projects-and-shell.md). */
final class ProjectChecklistQuery
{
    /** @return list<ChecklistStep> empty once it's dismissed, complete, or the viewer can't act on it */
    public function handle(Project $project, User $viewer): array
    {
        if ($project->checklist_dismissed_at !== null || ! $viewer->can('update', $project)) {
            return [];
        }

        $account = $project->account;
        $steps = [
            new ChecklistStep(__('Create the project'), __(':project is ready with a Production environment.', ['project' => $project->name]), true),
            new ChecklistStep(
                __('Turn on a service'),
                __('Deploy, Infrastructure, Monitoring or Analytics — pick what this project needs.'),
                $project->enabledServices()->exists(),
                __('Choose services'),
                route('projects.show', $project).'#services-heading',
            ),
            new ChecklistStep(
                __('Add a domain'),
                __('Optional. Verify the hostnames this project serves.'),
                $project->domains()->exists(),
                __('Add a domain'),
                route('projects.domains', $project),
            ),
        ];
        if ($viewer->can('manageMembers', $account)) {
            $steps[] = new ChecklistStep(
                __('Invite a teammate'),
                __('Optional. Members of :account can work on every project.', ['account' => $account->name]),
                $account->memberships()->count() > 1 || $account->invitations()->pending()->exists(),
                __('Invite someone'),
                route('account.members'),
            );
        }

        $allDone = array_filter($steps, fn (ChecklistStep $step): bool => ! $step->done) === [];

        return $allDone ? [] : $steps;
    }
}

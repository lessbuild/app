<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Project;
use App\Models\User;
use App\Notifications\Onboarding\NextStep;
use App\Queries\Projects\ProjectSetupQuery;

/**
 * Sends the one setup reminder to people who signed up two to seven days ago and haven't finished setting up: the
 * next step of their newest project, or making a first one. People who finished, turned these emails off, or were
 * already reminded are left alone.
 */
final class SendSetupReminders
{
    /**
     * Create a new SendSetupReminders instance.
     *
     * @param  ProjectSetupQuery  $setup  Works out a project's next step.
     */
    public function __construct(private readonly ProjectSetupQuery $setup) {}

    /**
     * Send the reminders that are due.
     *
     * @return int how many were sent
     */
    public function handle(): int
    {
        $sent = 0;
        User::query()->where('getting_started_emails', true)->whereNull('onboarding_nudged_at')->whereNotNull('email_verified_at')
            ->whereBetween('created_at', [now()->subDays(7), now()->subDays(2)])->orderBy('id')
            ->each(function (User $user) use (&$sent): void {
                $user->forceFill(['onboarding_nudged_at' => now()])->save();
                $project = $user->current_account_id === null ? null : Project::query()->where('account_id', $user->current_account_id)->where('is_sample', false)->latest('created_at')->first();
                if ($project === null) {
                    $user->notify(new NextStep(null, __('Create a project'), route('projects.create'), 0, 7));
                    $sent++;

                    return;
                }
                $setup = $this->setup->handle($project);
                $next = $setup->next();
                if ($next !== null) {
                    $user->notify(new NextStep($project->name, $next->title, route('projects.setup', $project), $setup->doneCount(), count($setup->steps)));
                    $sent++;
                }
            });

        return $sent;
    }
}

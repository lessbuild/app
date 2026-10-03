<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use Illuminate\Notifications\Messages\MailMessage;

/** A scheduled task started failing, or succeeded again after failing. Sent to members who manage the environment. */
final class ScheduledTaskStatusChanged extends InboxNotification
{
    /**
     * Create a new ScheduledTaskStatusChanged instance.
     *
     * A task's runs changed between failing and succeeding.
     *
     * @param  ScheduledTask  $task  The task.
     * @param  ScheduledTaskRun  $run  The run that changed it.
     */
    public function __construct(private readonly ScheduledTask $task, private readonly ScheduledTaskRun $run) {}

    /**
     * Get the notification's delivery channels: email, since a failing job may need attention, and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email: the title and body, with a link to the task.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('Open the task'));
    }

    /**
     * Get the headline: the task failed or recovered, in which environment.
     *
     * @return string
     */
    protected function title(): string
    {
        return $this->run->status === 'failed'
            ? __('Scheduled task :name failed in :environment', ['name' => $this->task->name, 'environment' => $this->task->environment->name])
            : __('Scheduled task :name recovered in :environment', ['name' => $this->task->name, 'environment' => $this->task->environment->name]);
    }

    /**
     * Say what happened, with the exit code of a failure.
     *
     * @return string
     */
    protected function body(): string
    {
        return $this->run->status === 'failed'
            ? __('The run exited with code :code. Its output is on the task’s page.', ['code' => $this->run->exit_code ?? '—'])
            : __('The latest run succeeded after earlier failures.');
    }

    /**
     * Get the environment's Automation tab, where the task and its runs are.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('deploy.environments.show', [$this->task->environment->project_id, $this->task->environment_id, 'tab' => 'automation']);
    }

    /**
     * Get the account of the task's project.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->task->environment->project->account_id;
    }
}

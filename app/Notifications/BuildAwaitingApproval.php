<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Build;
use Illuminate\Notifications\Messages\MailMessage;

/** A deploy (or promotion) to an environment that needs approval is waiting. Sent to the people who can approve it. */
final class BuildAwaitingApproval extends InboxNotification
{
    /**
     * Create a new BuildAwaitingApproval instance.
     *
     * A deploy to an environment that requires approval is waiting for someone to approve or reject it. Sent to the
     * members who may approve it.
     *
     * @param  Build  $build  The waiting deploy.
     */
    public function __construct(private readonly Build $build) {}

    /**
     * Get the notification's delivery channels: email, since a deploy may be waiting on it, and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email: the title and body, with a link to review the deploy.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('Review the deploy'));
    }

    /**
     * Get the headline, naming the deploy and the environment it's for.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('Deploy #:id to :environment needs approval', ['id' => $this->build->id, 'environment' => $this->build->environment->name ?? $this->build->website->name]);
    }

    /**
     * Say who asked for it and what: a promotion from another environment, or a deploy of a repository.
     *
     * @return string
     */
    protected function body(): string
    {
        $from = $this->build->promotedFrom?->environment?->name;

        return $from !== null
            ? __(':who wants to promote :commit from :from.', ['who' => $this->build->requester->name ?? __('Someone'), 'commit' => $this->build->shortRevision() ?? __('a release'), 'from' => $from])
            : __(':who started a deploy of :repository that waits for approval.', ['who' => $this->build->requester->name ?? __('A push'), 'repository' => $this->build->repository->name]);
    }

    /**
     * Get the deploy's page, where it can be approved or rejected.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('deploy.builds.show', [$this->build->repository->project_id, $this->build->id]);
    }

    /**
     * Get the account of the website being deployed to.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->build->website->account_id;
    }
}

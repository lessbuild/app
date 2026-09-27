<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Build;
use Illuminate\Notifications\Messages\MailMessage;

/** A deploy (or promotion) to an environment that needs approval is waiting. Sent to the people who can approve it. */
final class BuildAwaitingApproval extends InboxNotification
{
    public function __construct(private readonly Build $build) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title())->line($this->body())->action(__('Review the deploy'), $this->url());
    }

    protected function title(): string
    {
        return __('Deploy #:id to :environment needs approval', ['id' => $this->build->id, 'environment' => $this->build->environment->name ?? $this->build->website->name]);
    }

    protected function body(): string
    {
        $from = $this->build->promotedFrom?->environment?->name;

        return $from !== null
            ? __(':who wants to promote :commit from :from.', ['who' => $this->build->requester->name ?? __('Someone'), 'commit' => $this->build->shortRevision() ?? __('a release'), 'from' => $from])
            : __(':who started a deploy of :repository that waits for approval.', ['who' => $this->build->requester->name ?? __('A push'), 'repository' => $this->build->repository->name]);
    }

    protected function url(): string
    {
        return route('deploy.builds.show', [$this->build->repository->project_id, $this->build->id]);
    }

    protected function accountId(): string
    {
        return $this->build->website->account_id;
    }
}

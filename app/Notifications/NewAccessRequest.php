<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AccessRequest;
use Illuminate\Notifications\Messages\MailMessage;

/** Someone asked for access. Sent to platform admins. */
final class NewAccessRequest extends InboxNotification
{
    /**
     * Create a new NewAccessRequest instance.
     *
     * A new access request.
     *
     * @param  AccessRequest  $request  The request.
     */
    public function __construct(private readonly AccessRequest $request) {}

    /**
     * Get the delivery channels: email and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email with a link to the requests.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('Review requests'));
    }

    /**
     * Get the headline, naming who asked.
     *
     * @return string
     */
    protected function title(): string
    {
        return __(':name asked for access', ['name' => $this->request->name]);
    }

    /**
     * Get the company and team size they gave.
     *
     * @return string
     */
    protected function body(): string
    {
        return __(':company · team of :size', ['company' => $this->request->company ?? __('no company given'), 'size' => $this->request->team_size ?? '?']);
    }

    /**
     * Get the admin panel's access requests.
     *
     * @return string
     */
    protected function url(): string
    {
        return \App\Filament\Resources\AccessRequests\AccessRequestResource::getUrl(panel: 'admin');
    }
}

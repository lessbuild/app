<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Models\ServerAlertRule;
use Illuminate\Notifications\Messages\MailMessage;

/** A server alert rule tripped, or the metric is back within its threshold. Sent by email and to the inbox. */
final class ServerAlertChanged extends InboxNotification
{
    /**
     * Create a new ServerAlertChanged instance.
     *
     * A server alert rule tripped or recovered. Sent to the account's owners and admins.
     *
     * @param  ServerAlertRule  $rule  The rule that changed state.
     * @param  Server  $server  The server it watches.
     * @param  float  $value  The metric's value when it was evaluated.
     * @param  bool  $tripped  True when the rule crossed its threshold; false when the server recovered.
     */
    public function __construct(private readonly ServerAlertRule $rule, private readonly Server $server, private readonly float $value, private readonly bool $tripped) {}

    /**
     * Get the notification's delivery channels: email, so someone hears about it away from the app, and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email: the title and body, with a link to the server.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('Open the server'));
    }

    /**
     * Get the headline, naming the rule and server, and saying "recovered" when it's back.
     *
     * @return string
     */
    protected function title(): string
    {
        return $this->tripped
            ? __(':rule on :server', ['rule' => $this->rule->name, 'server' => $this->server->label()])
            : __(':rule on :server recovered', ['rule' => $this->rule->name, 'server' => $this->server->label()]);
    }

    /**
     * Describe the metric's value against the threshold, or the value it's back to.
     *
     * @return string
     */
    protected function body(): string
    {
        $metric = __(ServerAlertRule::METRICS[$this->rule->metric] ?? $this->rule->metric);
        $value = rtrim(rtrim(number_format($this->value, 2), '0'), '.');

        return $this->tripped
            ? __(':metric is :value (alert at :operator :threshold).', ['metric' => $metric, 'value' => $value, 'operator' => $this->rule->operator === 'lte' ? '≤' : '≥', 'threshold' => rtrim(rtrim(number_format($this->rule->threshold, 2), '0'), '.')])
            : __(':metric is back to :value.', ['metric' => $metric, 'value' => $value]);
    }

    /**
     * Get the server's page in the first project with Infrastructure on; servers belong to the account, not a project.
     *
     * @return string
     */
    protected function url(): string
    {
        $project = $this->server->account->projects()->whereHas('enabledServices', fn ($query) => $query->where('service', 'infrastructure'))->orderBy('created_at')->first();

        return $project !== null ? route('infrastructure.servers.show', [$project, $this->server->id]) : route('dashboard');
    }

    /**
     * Get the server's account, so the inbox shows it in the right account.
     *
     * @return string
     */
    protected function accountId(): string
    {
        return $this->server->account_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use App\Models\ServerAlertRule;
use Illuminate\Notifications\Messages\MailMessage;

/** A server alert rule tripped, or the metric is back within its threshold. Sent by email and to the inbox. */
final class ServerAlertChanged extends InboxNotification
{
    public function __construct(private readonly ServerAlertRule $rule, private readonly Server $server, private readonly float $value, private readonly bool $tripped) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title())->line($this->body())->action(__('Open the server'), $this->url());
    }

    protected function title(): string
    {
        return $this->tripped
            ? __(':rule on :server', ['rule' => $this->rule->name, 'server' => $this->server->label()])
            : __(':rule on :server recovered', ['rule' => $this->rule->name, 'server' => $this->server->label()]);
    }

    protected function body(): string
    {
        $metric = __(ServerAlertRule::METRICS[$this->rule->metric] ?? $this->rule->metric);
        $value = rtrim(rtrim(number_format($this->value, 2), '0'), '.');

        return $this->tripped
            ? __(':metric is :value (alert at :operator :threshold).', ['metric' => $metric, 'value' => $value, 'operator' => $this->rule->operator === 'lte' ? '≤' : '≥', 'threshold' => rtrim(rtrim(number_format($this->rule->threshold, 2), '0'), '.')])
            : __(':metric is back to :value.', ['metric' => $metric, 'value' => $value]);
    }

    /** The server's page in the first project with Infrastructure on; servers belong to the account, not a project. */
    protected function url(): string
    {
        $project = $this->server->account->projects()->whereHas('enabledServices', fn ($query) => $query->where('service', 'infrastructure'))->orderBy('created_at')->first();

        return $project !== null ? route('infrastructure.servers.show', [$project, $this->server->id]) : route('dashboard');
    }

    protected function accountId(): string
    {
        return $this->server->account_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Enums\AlertDestinationType;
use App\Models\AlertDestination;
use App\Models\Membership;
use App\Models\Project;
use App\Services\Monitoring\PublicWebhookTarget;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorisation happens in SaveAlertDestination (account admins). */
final class AlertDestinationRequest extends FormRequest
{
    /**
     * Get the validation rules for a destination's settings for its type: a verified member for email, a public HTTPS
     * endpoint on port 443 for webhooks and chat tools, and a routing key for PagerDuty. An existing destination's
     * type can't change.
     *
     * @param  PublicWebhookTarget  $targets
     * @return array<string, array<mixed>>
     */
    public function rules(PublicWebhookTarget $targets): array
    {
        $destination = $this->destination();
        $type = $destination->type ?? AlertDestinationType::tryFrom($this->string('type')->toString());
        $email = $type === AlertDestinationType::Email;
        $pagerDuty = $type === AlertDestinationType::PagerDuty;
        $accountId = $this->project()->account_id;

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'type' => ['required', Rule::enum(AlertDestinationType::class), ...($destination !== null ? [Rule::in([$destination->type->value])] : [])],
            'enabled' => ['required', 'boolean'],
            'version' => [$destination !== null ? 'required' : 'exclude', 'integer', 'min:0'],
            'recipient_user_id' => [$email ? 'required' : 'exclude', 'string', Rule::exists('users', 'id')->where(
                fn (Builder $query): Builder => $query->whereNotNull('email_verified_at')
                    ->whereIn('id', Membership::query()->where('account_id', $accountId)->select('user_id')),
            )],
            'endpoint_url' => [$email || $pagerDuty ? 'exclude' : ($destination !== null ? 'nullable' : 'required'), 'string', 'max:2048',
                function (string $attribute, mixed $value, Closure $fail) use ($targets, $type): void {
                    if (is_string($value) && $type !== null && $targets->host($value, $type) === null) {
                        $fail(__('Use a valid public HTTPS address on port 443 for this provider.'));
                    }
                },
            ],
            'signing_secret' => [$pagerDuty && $destination === null ? 'required' : ($pagerDuty ? 'nullable' : 'exclude'), 'string', 'min:16', 'max:256'],
        ];
    }

    /**
     * Get the project in the URL.
     *
     * @return Project
     */
    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }

    /**
     * Get the destination being edited, or null when creating one.
     *
     * @return AlertDestination|null
     */
    public function destination(): ?AlertDestination
    {
        $destination = $this->route('destination');

        return $destination instanceof AlertDestination ? $destination : null;
    }
}

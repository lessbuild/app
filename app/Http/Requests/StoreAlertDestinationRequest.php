<?php

namespace App\Http\Requests;

use App\Models\AlertDestination;
use App\Services\Entitlements;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertDestinationRequest extends FormRequest
{
    /**
     * Preserve management authorization and alert entitlement checks before destination validation.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->can('create', AlertDestination::class)) {
            return false;
        }

        app(Entitlements::class)->enforce($user->currentOrganization, 'alerts');

        return true;
    }

    /**
     * Validate the common destination fields before applying endpoint-type constraints.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(AlertDestination::TYPES)],
            'endpoint' => ['required', 'string', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(AlertDestination::EVENTS)],
        ];
    }

    /**
     * Preserve type-specific endpoint messages after common request validation succeeds.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $this->validated();
            $type = $data['type'];
            $endpoint = $data['endpoint'];
            if ($type === 'email' && ! filter_var($endpoint, FILTER_VALIDATE_EMAIL)) {
                $validator->errors()->add('endpoint', __('Enter a valid email address.'));

                return;
            }
            if ($type === 'pagerduty' && ! preg_match('/\A[a-zA-Z0-9_-]{20,100}\z/D', $endpoint)) {
                $validator->errors()->add('endpoint', __('Enter a valid PagerDuty Events API routing key.'));

                return;
            }
            $usesWebhookUrl = ! in_array($type, ['email', 'pagerduty'], true);
            if ($usesWebhookUrl && (! filter_var($endpoint, FILTER_VALIDATE_URL)
                || parse_url($endpoint, PHP_URL_SCHEME) !== 'https')) {
                $validator->errors()->add('endpoint', __('Webhook destinations must use a valid HTTPS URL.'));

                return;
            }
            $host = parse_url($endpoint, PHP_URL_HOST);
            if ($type === 'slack' && $host !== 'hooks.slack.com') {
                $validator->errors()->add('endpoint', __('Slack destinations must use hooks.slack.com.'));

                return;
            }
            if ($type === 'discord' && ! in_array($host, ['discord.com', 'discordapp.com'], true)) {
                $validator->errors()->add('endpoint', __('Discord destinations must use a Discord webhook URL.'));
            }
        });
    }
}

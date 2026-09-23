<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\PublicWebhookTarget;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveAlertDestinationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        if ($destination = $this->route('alertDestination')) {
            abort_unless($destination instanceof AlertDestination && AlertDestination::forWorkspace($workspace)->whereKey($destination->id)->exists(), 404);
            Gate::authorize('update', $destination);
        } else {
            Gate::authorize('create', [AlertDestination::class, $workspace]);
        }

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(CurrentWorkspace $currentWorkspace, PublicWebhookTarget $targets): array
    {
        $destination = $this->route('alertDestination');
        $data = $this->validationData();
        $type = $destination?->type ?? (is_string($data['type'] ?? null) ? AlertDestinationType::tryFrom($data['type']) : null);
        $email = $type === AlertDestinationType::Email;
        $pagerDuty = $type === AlertDestinationType::PagerDuty;

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'type' => ['required', Rule::enum(AlertDestinationType::class), ...($destination ? [Rule::in([$destination->type->value])] : [])],
            'enabled' => ['required', 'boolean'],
            'version' => [$destination ? 'required' : 'exclude', 'integer', 'min:0'],
            'recipient_user_id' => [$email ? 'required' : 'exclude', 'integer', Rule::exists('monitor.users', 'id')->where(
                fn (Builder $query): Builder => $query->whereNotNull('email_verified_at')
                    ->whereIn('id', $currentWorkspace->get()->members()->select('users.id')),
            )],
            'endpoint_url' => [$email || $pagerDuty ? 'exclude' : ($destination ? 'nullable' : 'required'), 'string', 'max:2048',
                function (string $attribute, mixed $value, Closure $fail) use ($targets, $type): void {
                    if (is_string($value) && $type !== null && $targets->host($value, $type) === null) {
                        $fail('Use a valid public HTTPS destination on port 443 for this provider.');
                    }
                },
            ],
            'signing_secret' => [$pagerDuty && $destination === null ? 'required' : ($pagerDuty ? 'nullable' : 'exclude'), 'string', 'min:16', 'max:256'],
        ];
    }
}

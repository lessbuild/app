<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\AlertDestinationType;
use App\Models\Account;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Monitoring\PublicWebhookTarget;
use App\Support\Webhooks\WebhookEvents;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveWebhookEndpoint
{
    /**
     * Create a new SaveWebhookEndpoint instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks the address is public HTTPS.
     */
    public function __construct(private readonly PublicWebhookTarget $targets) {}

    /**
     * Add an endpoint, or change one's address, events or whether it's on (turning it back on clears its failures).
     * A new endpoint gets a signing secret, returned once.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  array{url: string, description?: string|null, events: list<string>, enabled?: bool}  $data
     * @param  WebhookEndpoint|null  $endpoint  the one to change, or null for a new one
     * @return array{0: WebhookEndpoint, 1: string|null}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Account $account, array $data, ?WebhookEndpoint $endpoint = null): array
    {
        Gate::forUser($actor)->authorize('update', $account);
        abort_if($endpoint !== null && $endpoint->account_id !== $account->id, 404);
        $url = trim($data['url']);
        if ($this->targets->host($url, AlertDestinationType::Webhook) === null) {
            throw ValidationException::withMessages(['url' => __('Use a public HTTPS address on port 443.')]);
        }
        $events = in_array('*', $data['events'], true) ? ['*'] : array_values(array_intersect(WebhookEvents::names(), $data['events']));
        if ($events === []) {
            throw ValidationException::withMessages(['events' => __('Choose at least one event.')]);
        }
        $secret = $endpoint === null ? 'whsec_'.Str::random(40) : null;
        $endpoint ??= new WebhookEndpoint;
        $enabled = (bool) ($data['enabled'] ?? true);
        $description = trim((string) ($data['description'] ?? ''));
        $endpoint->forceFill([
            'account_id' => $account->id, 'created_by' => $endpoint->created_by ?? $actor->id, 'url' => $url,
            'description' => $description !== '' ? $description : null, 'events' => $events, 'enabled' => $enabled,
            ...($enabled && ! $endpoint->enabled ? ['failure_count' => 0, 'last_error' => null] : []),
            ...($secret !== null ? ['signing_secret' => $secret] : []),
        ])->save();

        return [$endpoint, $secret];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusWebhookSubscription;
use Illuminate\Http\JsonResponse;

final class ShowStatusWebhookUnsubscribeController
{
    /**
     * Show which status page a Slack or webhook subscription is for, so the person can confirm they want to stop it. A
     * wrong token is a 404.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @return JsonResponse
     */
    public function __invoke(string $subscription, string $token): JsonResponse
    {
        $record = StatusWebhookSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless(hash_equals($record->unsubscribe_token, $token), 404);

        return response()->json(['page' => ['name' => $record->statusPage->name, 'slug' => $record->statusPage->slug], 'type' => $record->type]);
    }
}

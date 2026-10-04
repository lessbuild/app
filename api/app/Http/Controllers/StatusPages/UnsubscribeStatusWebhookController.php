<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusWebhookSubscription;
use Illuminate\Http\JsonResponse;

final class UnsubscribeStatusWebhookController
{
    /**
     * Stop posting a status page's updates to a Slack channel or webhook, from the link in its messages, and send the
     * app to the status page. A wrong token is a 404.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @return JsonResponse
     */
    public function __invoke(string $subscription, string $token): JsonResponse
    {
        $record = StatusWebhookSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless(hash_equals($record->unsubscribe_token, $token), 404);
        $slug = $record->statusPage->slug;
        $record->delete();

        return response()->json(['redirect' => route('status.show', $slug, false), 'message' => __('Unsubscribed. No more updates will be posted there.')]);
    }
}

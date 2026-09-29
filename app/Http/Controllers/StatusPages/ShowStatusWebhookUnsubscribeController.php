<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusWebhookSubscription;
use Illuminate\Contracts\View\View;

final class ShowStatusWebhookUnsubscribeController
{
    /**
     * Ask to confirm stopping a Slack or webhook subscription (links in chat apps get opened by previews).
     *
     * @param  string  $subscription
     * @param  string  $token
     * @return View
     */
    public function __invoke(string $subscription, string $token): View
    {
        $record = StatusWebhookSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless(hash_equals($record->unsubscribe_token, $token), 404);

        return view('status-pages.unsubscribe-webhook', ['subscription' => $record, 'token' => $token]);
    }
}

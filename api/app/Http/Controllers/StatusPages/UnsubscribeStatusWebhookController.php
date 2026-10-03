<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusWebhookSubscription;
use Illuminate\Http\RedirectResponse;

final class UnsubscribeStatusWebhookController
{
    /**
     * Stop a Slack or webhook subscription and return to its page.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @return RedirectResponse
     */
    public function __invoke(string $subscription, string $token): RedirectResponse
    {
        $record = StatusWebhookSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless(hash_equals($record->unsubscribe_token, $token), 404);
        $slug = $record->statusPage->slug;
        $record->delete();

        return to_route('status.show', $slug)->with('status', __('Unsubscribed. No more updates will be posted there.'));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\UnsubscribeFromStatusPage;
use App\Models\StatusSubscription;
use Illuminate\Http\RedirectResponse;

/** Unsubscribes; also the one-click `List-Unsubscribe-Post` target, so it's exempt from CSRF (the token in the URL is the proof). */
final class UnsubscribeFromStatusPageController
{
    /**
     * Unsubscribes when the token matches; anything else is a 404.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @param  UnsubscribeFromStatusPage  $unsubscribe
     * @return RedirectResponse
     */
    public function __invoke(string $subscription, string $token, UnsubscribeFromStatusPage $unsubscribe): RedirectResponse
    {
        $record = StatusSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless($unsubscribe->handle($record, $token), 404);

        return to_route('status.show', $record->statusPage->slug)->with('status', __('You’ve been unsubscribed.'));
    }
}

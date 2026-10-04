<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\UnsubscribeFromStatusPage;
use App\Models\StatusSubscription;
use Illuminate\Http\JsonResponse;

final class UnsubscribeFromStatusPageController
{
    /**
     * Stop an email subscription, from the unsubscribe page or a mail client's one-click unsubscribe (the same address,
     * posted without a CSRF token), and send the app to the status page. A wrong token is a 404.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @param  UnsubscribeFromStatusPage  $unsubscribe
     * @return JsonResponse
     */
    public function __invoke(string $subscription, string $token, UnsubscribeFromStatusPage $unsubscribe): JsonResponse
    {
        $record = StatusSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless($unsubscribe->handle($record, $token), 404);

        return response()->json(['redirect' => route('status.show', $record->statusPage->slug, false), 'message' => __('You’ve been unsubscribed.')]);
    }
}

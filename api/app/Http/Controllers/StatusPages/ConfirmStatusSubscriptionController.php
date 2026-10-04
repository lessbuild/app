<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\ConfirmStatusSubscription;
use App\Models\StatusSubscription;
use Illuminate\Http\JsonResponse;

final class ConfirmStatusSubscriptionController
{
    /**
     * Confirm an email subscription from the link in its confirmation email and send the app to the status page. The
     * page posts this once it has loaded, so link checkers that only fetch the address don't confirm it. A wrong or
     * spent token is a 404.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @param  ConfirmStatusSubscription  $confirm
     * @return JsonResponse
     */
    public function __invoke(string $subscription, string $token, ConfirmStatusSubscription $confirm): JsonResponse
    {
        $record = StatusSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless($confirm->handle($record, $token), 404);

        return response()->json(['redirect' => route('status.show', $record->statusPage->slug, false), 'message' => __('You’ll get an email when this page posts an update.')]);
    }
}

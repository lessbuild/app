<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\PlatformStatusSubscriber;
use Illuminate\Http\JsonResponse;

final class UnsubscribeFromPlatformStatusController
{
    /**
     * Stop status emails for an address: from the page its emails link to, or one-click from the mail client.
     *
     * @param  PlatformStatusSubscriber  $subscriber
     * @param  string  $token
     * @return JsonResponse
     */
    public function __invoke(PlatformStatusSubscriber $subscriber, string $token): JsonResponse
    {
        abort_unless(hash_equals($subscriber->unsubscribe_token, $token), 404);
        $subscriber->delete();

        return response()->json(['redirect' => route('platform.status', [], false), 'message' => __('You won’t get any more status emails.')]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\PlatformStatusSubscriber;
use Illuminate\Http\JsonResponse;

final class ConfirmPlatformStatusSubscriptionController
{
    /**
     * Confirm a status subscription from its email's link (posted once the page has loaded, so mail scanners that
     * only fetch the link don't confirm it), then go back to the status page.
     *
     * @param  PlatformStatusSubscriber  $subscriber
     * @param  string  $token
     * @return JsonResponse
     */
    public function __invoke(PlatformStatusSubscriber $subscriber, string $token): JsonResponse
    {
        abort_if($subscriber->verification_token_hash === null || ! hash_equals($subscriber->verification_token_hash, hash('sha256', $token)), 404);
        $subscriber->forceFill(['verified_at' => now(), 'verification_token_hash' => null])->save();

        return response()->json(['redirect' => route('platform.status', [], false), 'message' => __('You’ll get an email when part of :app stops working, and again when it’s fixed.', ['app' => config('app.name')])]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Models\StatusSubscription;
use Illuminate\Contracts\View\View;

/**
 * The unsubscribe link in every update email (Deployer's URL shape). It asks first: mail scanners open links,
 * and that mustn't unsubscribe anyone.
 */
final class ShowUnsubscribeController
{
    /**
     * The unsubscribe confirmation page, when the token matches.
     *
     * @param  string  $subscription
     * @param  string  $token
     * @return View
     */
    public function __invoke(string $subscription, string $token): View
    {
        $record = StatusSubscription::query()->with('statusPage')->findOrFail((int) $subscription);
        abort_unless(hash_equals($record->unsubscribe_token, $token), 404);

        return view('status-pages.unsubscribe', ['subscription' => $record, 'token' => $token]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Status\ConfirmStatusSubscriptionAction;
use App\Actions\Status\SubscribeToStatusPageAction;
use App\Actions\Status\UnsubscribeStatusSubscriptionAction;
use App\Http\Requests\SubscribeToStatusPageRequest;
use App\Models\StatusSubscription;
use Illuminate\Http\RedirectResponse;

class StatusSubscriptionController extends Controller
{
    /**
     * Validate an email for a published status-page slug, reset its subscription verification, and send a confirmation link.
     */
    public function store(
        SubscribeToStatusPageRequest $request,
        SubscribeToStatusPageAction $subscribe,
    ): RedirectResponse {
        $subscribe->handle($request->statusPage(), $request->email());

        return back()->with('status_subscription', __('Check your email to confirm status updates.'));
    }

    /**
     * Match the one-time verification token, mark the subscription verified, and redirect to its status page.
     *
     * Invalid or already-used tokens return 404.
     */
    public function confirm(
        StatusSubscription $subscription,
        string $token,
        ConfirmStatusSubscriptionAction $confirm,
    ): RedirectResponse {
        abort_unless($confirm->handle($subscription, $token), 404);

        return redirect()->route('status.show', $subscription->statusPage->slug)
            ->with('status_subscription', __('Status updates are now enabled.'));
    }

    /**
     * Match the subscription's unsubscribe token, delete the subscription, and redirect to its status page.
     *
     * Invalid tokens return 404.
     */
    public function unsubscribe(
        StatusSubscription $subscription,
        string $token,
        UnsubscribeStatusSubscriptionAction $unsubscribe,
    ): RedirectResponse {
        $slug = $unsubscribe->handle($subscription, $token);
        abort_unless($slug !== null, 404);

        return redirect()->route('status.show', $slug)
            ->with('status_subscription', __('You have been unsubscribed.'));
    }
}

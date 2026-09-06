<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Notifications\ConfirmStatusSubscriptionNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class StatusSubscriptionController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();
        $email = strtolower($request->validate(['email' => ['required', 'email', 'max:254']])['email']);
        $token = Str::random(64);
        $subscription = $page->subscriptions()->updateOrCreate(
            ['email_hash' => hash('sha256', $email)],
            [
                'email' => $email,
                'verification_token_hash' => hash('sha256', $token),
                'unsubscribe_token' => Str::random(64),
                'verified_at' => null,
            ],
        );
        Notification::route('mail', $email)->notify(new ConfirmStatusSubscriptionNotification($subscription, $token));

        return back()->with('status_subscription', __('Check your email to confirm status updates.'));
    }

    public function confirm(StatusSubscription $subscription, string $token): RedirectResponse
    {
        abort_unless($subscription->verification_token_hash
            && hash_equals($subscription->verification_token_hash, hash('sha256', $token)), 404);
        $subscription->update(['verified_at' => now(), 'verification_token_hash' => null]);

        return redirect()->route('status.show', $subscription->statusPage->slug)
            ->with('status_subscription', __('Status updates are now enabled.'));
    }

    public function unsubscribe(StatusSubscription $subscription, string $token): RedirectResponse
    {
        abort_unless(hash_equals($subscription->unsubscribe_token, $token), 404);
        $slug = $subscription->statusPage->slug;
        $subscription->delete();

        return redirect()->route('status.show', $slug)
            ->with('status_subscription', __('You have been unsubscribed.'));
    }
}

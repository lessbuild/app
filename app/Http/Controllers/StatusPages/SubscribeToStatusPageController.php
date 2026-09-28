<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\SubscribeToStatusPage;
use App\Http\Requests\StatusPages\SubscribeToStatusPageRequest;
use App\Models\StatusPage;
use Illuminate\Http\RedirectResponse;

final class SubscribeToStatusPageController
{
    /**
     * Subscribes an email address and sends the confirmation email.
     *
     * @param  SubscribeToStatusPageRequest  $request
     * @param  string  $slug
     * @param  SubscribeToStatusPage  $subscribe
     * @return RedirectResponse
     */
    public function __invoke(SubscribeToStatusPageRequest $request, string $slug, SubscribeToStatusPage $subscribe): RedirectResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $subscribe->handle($page, $request->email());

        return to_route('status.show', $page->slug)->with('status', __('Check your email to confirm status updates.'));
    }
}

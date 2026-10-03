<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\SubscribeStatusWebhook;
use App\Models\StatusPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SubscribeStatusWebhookController
{
    /**
     * Subscribe a Slack channel or webhook to the page and return to it, showing a webhook's signing secret once.
     *
     * @param  Request  $request
     * @param  string  $slug
     * @param  SubscribeStatusWebhook  $subscribe
     * @return RedirectResponse
     */
    public function __invoke(Request $request, string $slug, SubscribeStatusWebhook $subscribe): RedirectResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $data = $request->validate(['channel' => ['required', 'in:slack,webhook'], 'url' => ['required', 'string', 'max:2048']]);
        [, $secret] = $subscribe->handle($page, (string) $data['channel'], (string) $data['url']);

        return to_route('status.show', $page->slug)
            ->with('status', $data['channel'] === 'slack' ? __('We’re posting a confirmation to your Slack channel; updates follow.') : __('We’re posting a confirmation to your webhook; updates follow once it answers with a success status.'))
            ->with('webhook_secret', $secret);
    }
}

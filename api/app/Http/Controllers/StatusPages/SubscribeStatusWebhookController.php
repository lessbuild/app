<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\SubscribeStatusWebhook;
use App\Models\StatusPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SubscribeStatusWebhookController
{
    /**
     * Post a published status page's updates to a Slack channel or a signed webhook. A webhook's signing secret comes
     * back once, in `secrets`.
     *
     * @param  Request  $request
     * @param  string  $slug
     * @param  SubscribeStatusWebhook  $subscribe
     * @return JsonResponse
     */
    public function __invoke(Request $request, string $slug, SubscribeStatusWebhook $subscribe): JsonResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $data = $request->validate(['channel' => ['required', 'in:slack,webhook'], 'url' => ['required', 'string', 'max:2048']]);
        [, $secret] = $subscribe->handle($page, (string) $data['channel'], (string) $data['url']);

        return response()->json([
            'message' => $data['channel'] === 'slack' ? __('We’re posting a confirmation to your Slack channel; updates follow.') : __('We’re posting a confirmation to your webhook; updates follow once it answers with a success status.'),
            'secrets' => $secret === null ? null : ['webhook_secret' => $secret],
        ]);
    }
}

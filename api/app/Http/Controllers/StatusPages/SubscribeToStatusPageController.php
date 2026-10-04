<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use App\Actions\Monitoring\SubscribeToStatusPage;
use App\Http\Requests\StatusPages\SubscribeToStatusPageRequest;
use App\Models\StatusPage;
use Illuminate\Http\JsonResponse;

final class SubscribeToStatusPageController
{
    /**
     * Subscribe an email address to a published status page's updates; the address confirms by email first.
     *
     * @param  SubscribeToStatusPageRequest  $request
     * @param  string  $slug
     * @param  SubscribeToStatusPage  $subscribe
     * @return JsonResponse
     */
    public function __invoke(SubscribeToStatusPageRequest $request, string $slug, SubscribeToStatusPage $subscribe): JsonResponse
    {
        $page = StatusPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $subscribe->handle($page, $request->email());

        return response()->json(['message' => __('Check your email to confirm status updates.')]);
    }
}

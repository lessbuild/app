<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Models\FeatureRequest;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ShowRoadmapController
{
    /**
     * Show the public roadmap: what's in progress, planned and under consideration (most voted first), what shipped
     * in the last 90 days, and which requests the signed-in person voted for.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $requests = FeatureRequest::query()->where('status', '!=', 'declined')
            ->where(fn ($query) => $query->where('status', '!=', 'shipped')->orWhere('shipped_at', '>=', now()->subDays(90)))
            ->orderByDesc('votes_count')->orderByDesc('id')->get();
        $user = $request->user();
        $row = fn (FeatureRequest $item): array => [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'votes' => $item->votes_count,
            'shippedAt' => $item->shipped_at?->toIso8601String(),
        ];

        return response()->json([
            'meta' => PageMeta::for(__('Roadmap'), __('What’s being built for :app, what’s next, and what people are asking for.', ['app' => config('app.name')]), route('roadmap'), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), __('Roadmap') => route('roadmap')]),
            ]),
            'columns' => collect(['in_progress', 'planned', 'under_review'])->map(fn (string $status): array => [
                'status' => $status,
                'requests' => $requests->where('status', $status)->map($row)->values(),
            ]),
            'shipped' => $requests->where('status', 'shipped')->sortByDesc('shipped_at')->map($row)->values(),
            'voted' => $user === null ? [] : array_map(intval(...), DB::table('feature_request_votes')->where('user_id', $user->getAuthIdentifier())->pluck('feature_request_id')->all()),
            'signedIn' => $user !== null,
        ])->header('Vary', 'Cookie, Accept-Language');
    }
}

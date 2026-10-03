<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Models\FeatureRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** `/roadmap`: what's being built, what's planned, what shipped, and what people are asking for. */
final class ShowRoadmapController
{
    /**
     * Show the roadmap: in progress and planned requests, requests under consideration by votes, and what shipped in
     * the last 90 days. Signed-in people see which ones they voted for.
     *
     * @param  Request  $request
     * @return View
     */
    public function __invoke(Request $request): View
    {
        $requests = FeatureRequest::query()->where('status', '!=', 'declined')
            ->where(fn ($query) => $query->where('status', '!=', 'shipped')->orWhere('shipped_at', '>=', now()->subDays(90)))
            ->orderByDesc('votes_count')->orderByDesc('id')->get();
        $user = $request->user();
        $voted = $user === null ? [] : array_map(intval(...), DB::table('feature_request_votes')->where('user_id', $user->getAuthIdentifier())->pluck('feature_request_id')->all());

        return view('site.roadmap', [
            'columns' => [
                'in_progress' => $requests->where('status', 'in_progress')->values(),
                'planned' => $requests->where('status', 'planned')->values(),
                'under_review' => $requests->where('status', 'under_review')->values(),
            ],
            'shipped' => $requests->where('status', 'shipped')->sortByDesc('shipped_at')->values(),
            'voted' => $voted,
        ]);
    }
}

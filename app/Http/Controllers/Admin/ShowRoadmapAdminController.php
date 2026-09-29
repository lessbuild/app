<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\FeatureRequest;
use Illuminate\Contracts\View\View;

final class ShowRoadmapAdminController
{
    /**
     * Show every roadmap request with its votes and linked feedback, for writing up and moving along.
     *
     * @return View
     */
    public function __invoke(): View
    {
        return view('admin.roadmap', [
            'requests' => FeatureRequest::query()->withCount('feedback')->orderByRaw("case status when 'in_progress' then 0 when 'planned' then 1 when 'under_review' then 2 when 'shipped' then 3 else 4 end")
                ->orderByDesc('votes_count')->orderByDesc('id')->get(),
        ]);
    }
}

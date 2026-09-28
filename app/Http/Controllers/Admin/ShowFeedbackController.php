<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Feedback;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowFeedbackController
{
    /**
     * Show feedback, open items by default, newest first, 50 a page.
     *
     * @param  Request  $request
     * @return View
     */
    public function __invoke(Request $request): View
    {
        $resolved = $request->query('show') === 'resolved';

        return view('admin.feedback', [
            'resolved' => $resolved,
            'counts' => ['open' => Feedback::query()->whereNull('resolved_at')->count(), 'resolved' => Feedback::query()->whereNotNull('resolved_at')->count()],
            'items' => Feedback::query()->when($resolved, fn ($query) => $query->whereNotNull('resolved_at'), fn ($query) => $query->whereNull('resolved_at'))
                ->with(['user', 'account', 'resolver'])->latest('created_at')->paginate(50)->withQueryString(),
        ]);
    }
}

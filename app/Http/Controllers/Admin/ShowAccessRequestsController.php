<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AccessRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowAccessRequestsController
{
    /**
     * Show access requests with one status (waiting ones by default), oldest first, 50 a page.
     *
     * @param  Request  $request
     * @return View
     */
    public function __invoke(Request $request): View
    {
        $status = in_array($request->query('status'), AccessRequest::STATUSES, true) ? (string) $request->query('status') : 'pending';

        return view('admin.access-requests', [
            'status' => $status,
            'counts' => AccessRequest::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all(),
            'requests' => AccessRequest::query()->where('status', $status)->with('reviewer')->oldest('created_at')->paginate(50)->withQueryString(),
            'registrationOpen' => (bool) config('platform.registration.open'),
        ]);
    }
}

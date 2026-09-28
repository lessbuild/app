<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\PlatformAdminEvent;
use Illuminate\Contracts\View\View;

final class ShowAdminHomeController
{
    /**
     * Show the admin panel's home: its sections and the latest entries in the admin trail.
     *
     * @return View
     */
    public function __invoke(): View
    {
        return view('admin.home', ['events' => PlatformAdminEvent::query()->with(['actor', 'subjectUser', 'subjectAccount'])->latest('id')->limit(50)->get()]);
    }
}

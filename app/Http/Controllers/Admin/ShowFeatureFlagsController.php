<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\FeatureFlag;
use Illuminate\Contracts\View\View;

final class ShowFeatureFlagsController
{
    /**
     * Show every feature flag and the form for a new one.
     *
     * @return View
     */
    public function __invoke(): View
    {
        return view('admin.flags', ['flags' => FeatureFlag::query()->with('editor')->orderBy('key')->get()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Roadmap\SaveFeatureRequest;
use App\Http\Requests\Admin\SaveFeatureRequestRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreFeatureRequestController
{
    /**
     * Add a request to the roadmap and return to the roadmap admin.
     *
     * @param  SaveFeatureRequestRequest  $request
     * @param  User  $user
     * @param  SaveFeatureRequest  $save
     * @return RedirectResponse
     */
    public function __invoke(SaveFeatureRequestRequest $request, #[CurrentUser] User $user, SaveFeatureRequest $save): RedirectResponse
    {
        $save->handle($user, null, $request->details());

        return to_route('admin.roadmap')->with('status', __('Added to the roadmap.'));
    }
}

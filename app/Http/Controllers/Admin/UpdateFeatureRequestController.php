<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Roadmap\SaveFeatureRequest;
use App\Http\Requests\Admin\SaveFeatureRequestRequest;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateFeatureRequestController
{
    /**
     * Update a roadmap request (moving it to shipped tells its voters) and return to the roadmap admin.
     *
     * @param  SaveFeatureRequestRequest  $request
     * @param  User  $user
     * @param  FeatureRequest  $featureRequest
     * @param  SaveFeatureRequest  $save
     * @return RedirectResponse
     */
    public function __invoke(SaveFeatureRequestRequest $request, #[CurrentUser] User $user, FeatureRequest $featureRequest, SaveFeatureRequest $save): RedirectResponse
    {
        $save->handle($user, $featureRequest, $request->details());

        return to_route('admin.roadmap')->with('status', __('Roadmap request saved.'));
    }
}

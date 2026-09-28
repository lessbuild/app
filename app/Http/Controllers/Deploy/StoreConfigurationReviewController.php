<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\CreateConfigurationReview;
use App\Http\Requests\Deploy\ConfigurationRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreConfigurationReviewController
{
    /**
     * Opens a review of the posted configuration document.
     *
     * @param  ConfigurationRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  CreateConfigurationReview  $create
     * @return RedirectResponse
     */
    public function __invoke(ConfigurationRequest $request, #[CurrentUser] User $user, Project $project, CreateConfigurationReview $create): RedirectResponse
    {
        $review = $create->handle($user, $project, $request->document(), $request->bindings());

        return to_route('deploy.configuration.reviews.show', [$project, $review->id]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateSampleProject;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/projects/sample`. */
final class StoreSampleProjectController
{
    /**
     * Create the sample project (made-up visits and errors, nothing reaching the outside world) and open its Analytics.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  CreateSampleProject  $sample
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[CurrentAccount] Account $account, CreateSampleProject $sample): JsonResponse
    {
        $project = $sample->handle($user, $account);

        return response()->json([
            'id' => $project->id,
            'redirect' => route('analytics.overview', $project, false),
            'message' => __('Here’s a sample project with a month of made-up visits and a day of made-up errors. Look around, then delete it from its settings.'),
        ], 201);
    }
}

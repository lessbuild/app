<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\VerifyStatusPageDomain;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class VerifyStatusPageDomainController
{
    /**
     * Check the custom domain's TXT record and return to the page with the result.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  VerifyStatusPageDomain  $verify
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, StatusPage $page, VerifyStatusPageDomain $verify): JsonResponse
    {
        if (! $verify->handle($user, $page)) {
            throw ValidationException::withMessages(['custom_domain' => __('The TXT record isn’t there yet. DNS changes can take a few minutes to appear.')]);
        }

        return response()->json([
            'redirect' => route('monitoring.status-pages.show', [$project, $page->id], false),
            'message' => __('Domain verified. The page will be served there once its DNS points at us.'),
        ]);
    }
}

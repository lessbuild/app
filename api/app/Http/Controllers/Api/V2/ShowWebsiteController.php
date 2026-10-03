<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\User;
use App\Models\Website;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowWebsiteController
{
    /**
     * Show a website, with its setup status (`GET /api/v2/websites/{id}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  int  $websiteId
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, int $websiteId): JsonResponse
    {
        $website = Website::query()->where('account_id', ResourceJson::account($request)->id)->findOrFail($websiteId);
        abort_unless($user->can('view', $website), 404);

        return response()->json(['data' => ResourceJson::website($website)]);
    }
}

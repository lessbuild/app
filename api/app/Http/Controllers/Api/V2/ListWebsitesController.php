<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\User;
use App\Models\Website;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListWebsitesController
{
    /**
     * List the account's websites (`GET /api/v2/websites`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $websites = Website::query()->where('account_id', ResourceJson::account($request)->id)->orderBy('name')->get()->filter(fn (Website $website): bool => $user->can('view', $website));

        return response()->json(['data' => $websites->map(ResourceJson::website(...))->values()]);
    }
}

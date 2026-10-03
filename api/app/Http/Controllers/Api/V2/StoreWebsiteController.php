<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Infrastructure\CreateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreWebsiteController
{
    /**
     * Create a website on one of the account's app servers (`POST /api/v2/websites`). It answers at once while the
     * website is set up; poll it until its status is active or failed.
     *
     * @param  WebsiteRequest  $request
     * @param  User  $user
     * @param  CreateWebsite  $create
     * @return JsonResponse
     */
    public function __invoke(WebsiteRequest $request, #[CurrentUser] User $user, CreateWebsite $create): JsonResponse
    {
        $website = $create->handle(ResourceJson::account($request), $user, $request->validated());

        return response()->json(['data' => ResourceJson::website($website)], 201);
    }
}

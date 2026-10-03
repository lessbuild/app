<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Infrastructure\UpdateWebsite;
use App\Http\Requests\Infrastructure\WebsiteRequest;
use App\Models\User;
use App\Models\Website;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateWebsiteController
{
    /**
     * Change a website (`PUT /api/v2/websites/{id}`); a new server or address sets it up again.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  int  $websiteId
     * @param  UpdateWebsite  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, int $websiteId, UpdateWebsite $update): JsonResponse
    {
        $account = ResourceJson::account($request);
        $website = Website::query()->where('account_id', $account->id)->findOrFail($websiteId);
        abort_unless($user->can('view', $website), 404);
        // The form's rules let the website keep its own address once they know which website it is.
        $request->route()?->setParameter('website', $website);

        return response()->json(['data' => ResourceJson::website($update->handle($account, $user, $website, app(WebsiteRequest::class)->validated()))]);
    }
}

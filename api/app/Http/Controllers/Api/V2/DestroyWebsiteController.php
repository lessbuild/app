<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Infrastructure\DeleteWebsite;
use App\Models\User;
use App\Models\Website;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DestroyWebsiteController
{
    /**
     * Delete a website and remove it from its server (`DELETE /api/v2/websites/{id}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  int  $websiteId
     * @param  DeleteWebsite  $delete
     * @return Response
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, int $websiteId, DeleteWebsite $delete): Response
    {
        $account = ResourceJson::account($request);
        $website = Website::query()->where('account_id', $account->id)->findOrFail($websiteId);
        abort_unless($user->can('view', $website), 404);
        $delete->handle($account, $user, $website);

        return response()->noContent();
    }
}

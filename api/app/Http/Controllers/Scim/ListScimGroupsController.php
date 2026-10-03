<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Support\Identity\ScimResources;
use Illuminate\Http\JsonResponse;

final class ListScimGroupsController
{
    /**
     * List groups: none, since roles are set in the app; identity providers that probe for groups get an empty list.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return ScimResources::respond(ScimResources::list([], 0));
    }
}

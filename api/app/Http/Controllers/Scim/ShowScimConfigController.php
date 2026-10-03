<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Support\Identity\ScimResources;
use Illuminate\Http\JsonResponse;

final class ShowScimConfigController
{
    /**
     * Describe what our SCIM service supports: users with PATCH and filtering by userName or externalId; no bulk,
     * sorting, password changes or groups.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return ScimResources::respond([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200], 'changePassword' => ['supported' => false],
            'sort' => ['supported' => false], 'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'The SCIM token from Account → Security.', 'primary' => true]],
        ]);
    }
}

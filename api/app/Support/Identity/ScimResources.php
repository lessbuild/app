<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Models\ScimUser;
use Illuminate\Http\JsonResponse;

/** Builds the SCIM documents we answer with. */
final class ScimResources
{
    /**
     * Describe a provisioned person as a SCIM User.
     *
     * @param  ScimUser  $scimUser
     * @return array<string, mixed>
     */
    public static function user(ScimUser $scimUser): array
    {
        $user = $scimUser->user;

        return array_filter([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => $scimUser->id,
            'externalId' => $scimUser->external_id,
            'userName' => $user->email,
            'displayName' => $user->name,
            'name' => ['formatted' => $user->name],
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => $scimUser->active,
            'meta' => [
                'resourceType' => 'User', 'location' => route('scim.users.show', $scimUser->id),
                'created' => $scimUser->created_at?->toIso8601String(), 'lastModified' => $scimUser->updated_at?->toIso8601String(),
            ],
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Wrap a document in a SCIM response.
     *
     * @param  array<string, mixed>  $document
     * @param  int  $status
     * @return JsonResponse
     */
    public static function respond(array $document, int $status = 200): JsonResponse
    {
        return response()->json($document, $status, ['Content-Type' => 'application/scim+json'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a list response.
     *
     * @param  list<array<string, mixed>>  $resources
     * @param  int  $total
     * @param  int  $startIndex
     * @return array<string, mixed>
     */
    public static function list(array $resources, int $total, int $startIndex = 1): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total, 'startIndex' => $startIndex, 'itemsPerPage' => count($resources), 'Resources' => $resources,
        ];
    }
}

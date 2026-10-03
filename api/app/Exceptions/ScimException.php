<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A SCIM request that can't be carried out, answered with a SCIM error document. */
final class ScimException extends RuntimeException
{
    /**
     * Create a new ScimException instance.
     *
     * @param  int  $status  The HTTP status.
     * @param  string  $detail  What went wrong, for the identity provider's logs.
     * @param  string|null  $scimType  SCIM's error keyword, such as uniqueness or invalidValue.
     */
    public function __construct(public readonly int $status, string $detail, public readonly ?string $scimType = null)
    {
        parent::__construct($detail);
    }

    /**
     * Render the SCIM error document.
     *
     * @return JsonResponse
     */
    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => (string) $this->status, 'scimType' => $this->scimType, 'detail' => $this->getMessage(),
        ], fn ($value): bool => $value !== null), $this->status, ['Content-Type' => 'application/scim+json']);
    }
}

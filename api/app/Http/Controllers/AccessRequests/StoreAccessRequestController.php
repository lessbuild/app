<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccessRequests;

use App\Actions\AccessRequests\SubmitAccessRequest;
use App\Models\AccessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** `POST /api/app/access-requests`. */
final class StoreAccessRequestController
{
    /**
     * Ask for access while sign-up is closed; the person gets a receipt by email.
     *
     * @param  Request  $request
     * @param  SubmitAccessRequest  $submit
     * @return JsonResponse
     */
    public function __invoke(Request $request, SubmitAccessRequest $submit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255'], 'company' => ['nullable', 'string', 'max:120'],
            'team_size' => ['nullable', Rule::in(AccessRequest::TEAM_SIZES)], 'use_case' => ['required', 'string', 'max:2000'],
        ]);
        $submit->handle([
            'name' => (string) $data['name'], 'email' => (string) $data['email'], 'company' => isset($data['company']) ? (string) $data['company'] : null,
            'team_size' => isset($data['team_size']) ? (string) $data['team_size'] : null, 'use_case' => (string) $data['use_case'],
        ]);

        return response()->json(['message' => __('Thanks. We’ve emailed you a receipt and will be in touch.')], 201);
    }
}

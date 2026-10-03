<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccessRequests;

use App\Models\AccessRequest;
use App\Services\Users\Registration;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/access-requests`. */
final class ShowAccessRequestFormController
{
    /**
     * Return whether sign-up is open (then there's nothing to request) and the team sizes the form offers.
     *
     * @param  Registration  $registration
     * @return JsonResponse
     */
    public function __invoke(Registration $registration): JsonResponse
    {
        return response()->json(['registrationOpen' => $registration->isOpen(), 'teamSizes' => AccessRequest::TEAM_SIZES]);
    }
}

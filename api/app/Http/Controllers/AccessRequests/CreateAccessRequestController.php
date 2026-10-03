<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccessRequests;

use App\Models\AccessRequest;
use App\Services\Users\Registration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class CreateAccessRequestController
{
    /**
     * Show the access request form while registration is closed; send people to sign-up when it's open.
     *
     * @param  Registration  $registration
     * @return View|RedirectResponse
     */
    public function __invoke(Registration $registration): View|RedirectResponse
    {
        return $registration->isOpen() ? to_route('register') : view('access-requests.create', ['teamSizes' => AccessRequest::TEAM_SIZES]);
    }
}

<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Services\RegistrationAccess;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicPageController extends Controller
{
    /**
     * @param  RegistrationAccess  $registration  Current registration availability.
     * @return View The configured plans and registration entry point.
     */
    public function pricing(RegistrationAccess $registration): View
    {
        return view('scenes.pricing', [
            'plans' => config('billing.plans'),
            'registrationOpen' => $registration->allowsNewUser(),
        ]);
    }

    /** @return BinaryFileResponse The public OpenAPI contract with its original content type and cache lifetime. */
    public function openapi(): BinaryFileResponse
    {
        return response()->file(public_path('openapi.json'), [
            'Cache-Control' => 'public, max-age=300',
            'Content-Type' => 'application/json',
        ]);
    }
}

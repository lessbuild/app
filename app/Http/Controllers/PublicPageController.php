<?php

namespace App\Http\Controllers;

use App\Services\RegistrationAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicPageController extends Controller
{
    /**
     * @param  Request  $request  The visitor's current authentication context.
     * @return View|RedirectResponse The public landing page, or the signed-in dashboard redirect.
     */
    public function home(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return view('scenes.index');
    }

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

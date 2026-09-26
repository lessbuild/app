<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Services\Core\DeployerApiDocumentationProvider;
use App\Modules\Deployer\Services\RegistrationAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

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

    /** @return JsonResponse The current configured-host OpenAPI contract. */
    public function openapi(DeployerApiDocumentationProvider $documentation): JsonResponse
    {
        $reference = $documentation->reference();
        abort_if($reference === null, 404);

        return response()->json($reference->document)->header('Cache-Control', 'public, max-age=300');
    }
}

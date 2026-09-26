<?php

namespace App\Core\Http\Controllers;

use App\Modules\Deployer\Actions\AccessRequest\SubmitAccessRequestAction;
use App\Modules\Deployer\Http\Requests\StoreAccessRequestRequest;
use App\Modules\Deployer\Models\AccessRequest;
use App\Modules\Deployer\Services\RegistrationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CoreAccessRequestController
{
    public function create(Request $request, RegistrationAccess $registration): View|RedirectResponse
    {
        if ($registration->allowsNewUser()) {
            return to_route('platform.register');
        }

        $requestedPlan = $request->query('plan');

        return view('core::marketing.access-request', [
            'plans' => config('billing.plans', []),
            'teamSizes' => AccessRequest::TEAM_SIZES,
            'selectedPlan' => is_string($requestedPlan) && array_key_exists($requestedPlan, config('billing.plans', []))
                ? $requestedPlan
                : null,
        ]);
    }

    public function store(
        StoreAccessRequestRequest $request,
        SubmitAccessRequestAction $submit,
    ): RedirectResponse {
        if (filled($request->input('website'))) {
            return to_route('core.access-request.create')
                ->with('access_requested', $this->successMessage());
        }

        $submit->handle($request->applicantAttributes());

        return to_route('core.access-request.create')
            ->with('access_requested', $this->successMessage());
    }

    private function successMessage(): string
    {
        return __('Thanks — your request is on the list. We will contact you at the email you provided.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\AccessRequest\SubmitAccessRequestAction;
use App\Http\Requests\StoreAccessRequestRequest;
use App\Services\RegistrationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccessRequestController extends Controller
{
    /**
     * Show the access-request form with a recognized plan, or redirect to open registration.
     */
    public function create(Request $request, RegistrationAccess $registration): View|RedirectResponse
    {
        if ($registration->allowsNewUser()) {
            return redirect()->route('register');
        }

        return view('access-request', [
            'selectedPlan' => $this->plan($request->query('plan')),
        ]);
    }

    /**
     * Validate applicant details, preserve prior review decisions, and acknowledge the request.
     *
     * New requests notify the applicant and platform administrators; honeypots are successful no-ops.
     */
    public function store(StoreAccessRequestRequest $request, SubmitAccessRequestAction $submit): RedirectResponse
    {
        // Honeypot fields are intentionally accepted as a successful no-op.
        if (filled($request->input('website'))) {
            return back()->with('access_requested', $this->successMessage());
        }

        $submit->handle($request->applicantAttributes());

        return redirect()->route('access-request.create')->with('access_requested', $this->successMessage());
    }

    /**
     * Accept a configured billing-plan key from untrusted query input; return null otherwise.
     */
    private function plan(mixed $plan): ?string
    {
        return is_string($plan) && array_key_exists($plan, config('billing.plans', [])) ? $plan : null;
    }

    /**
     * Return the same acknowledgement for new, existing, and honeypot submissions.
     */
    private function successMessage(): string
    {
        return __('Thanks — your request is on the list. We will contact you at the email you provided.');
    }
}

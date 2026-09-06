<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest as AccessRequestModel;
use App\Notifications\AccessRequestReceivedNotification;
use App\Notifications\NewAccessRequestNotification;
use App\Services\RegistrationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccessRequestController extends Controller
{
    public function create(Request $request, RegistrationAccess $registration): View|RedirectResponse
    {
        if ($registration->allowsNewUser()) {
            return redirect()->route('register');
        }

        return view('access-request', [
            'selectedPlan' => $this->plan($request->query('plan')),
        ]);
    }

    public function store(Request $request, RegistrationAccess $registration): RedirectResponse
    {
        if ($registration->allowsNewUser()) {
            return redirect()->route('register');
        }

        // Honeypot fields are intentionally accepted as a successful no-op.
        if (filled($request->input('website'))) {
            return back()->with('access_requested', $this->successMessage());
        }

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'company' => ['nullable', 'string', 'max:160'],
            'team_size' => ['nullable', Rule::in(AccessRequestModel::TEAM_SIZES)],
            'plan' => ['nullable', Rule::in(array_keys(config('billing.plans', [])))],
            'use_case' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $emailHash = hash('sha256', $validated['email']);
        $existing = AccessRequestModel::query()->where('email_hash', $emailHash)->first();
        $attributes = [
            'email_hash' => $emailHash,
            'email' => $validated['email'],
            'name' => trim($validated['name']),
            'company' => filled($validated['company'] ?? null) ? trim($validated['company']) : null,
            'team_size' => $validated['team_size'] ?? null,
            'plan' => $validated['plan'] ?? null,
            'use_case' => trim($validated['use_case']),
        ];

        $created = false;
        if ($existing) {
            // Preserve an operator's decision while allowing pending leads to add context.
            if ($existing->status === 'pending') {
                $existing->update($attributes);
            }
        } else {
            AccessRequestModel::query()->create($attributes);
            $created = true;
        }

        if ($created) {
            Notification::route('mail', $validated['email'])->notify(new AccessRequestReceivedNotification);
            foreach (config('lessbuild.platform_admin_emails', []) as $adminEmail) {
                Notification::route('mail', $adminEmail)->notify(new NewAccessRequestNotification);
            }
        }

        return redirect()->route('access-request.create')->with('access_requested', $this->successMessage());
    }

    private function plan(mixed $plan): ?string
    {
        return is_string($plan) && array_key_exists($plan, config('billing.plans', [])) ? $plan : null;
    }

    private function successMessage(): string
    {
        return __('Thanks — your request is on the list. We will contact you at the email you provided.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Organization\AcceptOrganizationInvitationAction;
use App\Actions\Organization\InviteOrganizationMemberAction;
use App\Actions\Organization\RemoveOrganizationMemberAction;
use App\Actions\Organization\SwitchOrganizationAction;
use App\Actions\Organization\UpdateOrganizationMemberAction;
use App\Exceptions\OrganizationInvitationOperationException;
use App\Exceptions\OrganizationMemberOperationException;
use App\Http\Requests\AcceptOrganizationInvitationRequest;
use App\Http\Requests\StoreOrganizationInvitationRequest;
use App\Http\Requests\UpdateOrganizationMemberRequest;
use App\Models\Build;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\PersonalOrganization;
use App\Services\PlanLimits;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    /**
     * Ensure and authorize the current workspace, then render members, outstanding invitations, and the seat allowance.
     */
    public function index(Request $request, PersonalOrganization $personal, PlanLimits $limits): View
    {
        $organization = $personal->ensure($request->user());
        abort_unless($organization->permits($request->user(), 'view'), 403);

        return view('scenes.organizations.index', [
            'organization' => $organization->load('members'),
            'invitations' => $organization->invitations()->whereNull('accepted_at')->latest()->get(),
            'canManage' => $organization->permits($request->user(), 'manage'),
            'memberUsage' => $limits->usage($request->user(), 'members'),
        ]);
    }

    /**
     * Validate an entitled workspace manager's email and role invitation against domain and member limits.
     *
     * @return RedirectResponse A sent acknowledgement after creating a seven-day hashed-token invitation.
     */
    public function invite(StoreOrganizationInvitationRequest $request, InviteOrganizationMemberAction $invite): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        try {
            $invite->handle($organization, $request->user(), $request->validated());
        } catch (OrganizationInvitationOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Invitation sent.'));
    }

    /**
     * Validate the invitation token, email ownership, expiry, and seat allowance under locks before joining the workspace.
     *
     * @return RedirectResponse Workspace settings after switching membership and queuing billing-seat synchronization.
     */
    public function accept(AcceptOrganizationInvitationRequest $request, AcceptOrganizationInvitationAction $accept, OrganizationInvitation $invitation): RedirectResponse
    {
        $accept->handle($request->user(), $invitation, $request->token());

        return redirect()->route('organizations.index')->with('success', __('Workspace invitation accepted.'));
    }

    /**
     * Require visibility of the bound workspace, select it as current, and redirect to its dashboard.
     */
    public function switch(Request $request, Organization $organization, SwitchOrganizationAction $switch): RedirectResponse
    {
        $this->authorize('switch', $organization);
        $switch->handle($request->user(), $organization);

        return redirect()->route('dashboard')->with('success', __('Workspace changed to :name.', ['name' => $organization->name]));
    }

    /**
     * Validate a role for an existing non-owner member of the managed workspace, update the membership, and redirect back.
     */
    public function updateMember(UpdateOrganizationMemberRequest $request, User $member, UpdateOrganizationMemberAction $updateMember): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        try {
            $updateMember->handle($organization, $member, $request->role());
        } catch (OrganizationMemberOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Member role updated.'));
    }

    /**
     * Require workspace management access, validate categories and recovery alerts, and save normalized notification preferences.
     */
    public function updateNotificationPreferences(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization?->permits($request->user(), 'manage'), 403);
        $data = $request->validate([
            'categories' => ['nullable', 'array'],
            'categories.*' => ['required', Rule::in(['website', 'server', 'deployment', 'provider', 'security', 'recipe']), 'distinct'],
            'recoveries' => ['required', 'boolean'],
        ]);
        $organization->update(['notification_preferences' => [
            'categories' => array_values($data['categories'] ?? []),
            'recoveries' => (bool) $data['recoveries'],
        ]]);

        return back()->with('success', __('Workspace notification preferences updated.'));
    }

    /**
     * Validate workspace network, email-domain, authentication, idle-timeout, and SSO policy settings before saving them.
     *
     * New IP restrictions must retain the current client address; changed SSO settings require the SSO entitlement.
     */
    public function updateSecurityPolicy(Request $request, Entitlements $entitlements): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization?->permits($request->user(), 'manage'), 403);
        $data = $request->validate([
            'allowed_ip_ranges' => ['nullable', 'string', 'max:5000'],
            'allowed_email_domains' => ['nullable', 'string', 'max:2000'],
            'require_two_factor' => ['required', 'boolean'],
            'session_idle_minutes' => ['nullable', 'integer', Rule::in([15, 30, 60, 240, 720, 1440])],
            'sso_issuer' => ['nullable', 'url:https', 'max:1000'],
            'sso_client_id' => ['nullable', 'string', 'max:500'],
            'sso_client_secret' => ['nullable', 'string', 'max:2000'],
            'sso_enforced' => ['required', 'boolean'],
        ]);
        $ranges = collect(preg_split('/[\s,]+/', (string) ($data['allowed_ip_ranges'] ?? '')) ?: [])->filter()->values();
        foreach ($ranges as $range) {
            [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
            $packed = @inet_pton($network);
            $bits = $prefix === null ? ($packed === false ? -1 : strlen($packed) * 8) : filter_var($prefix, FILTER_VALIDATE_INT);
            if ($packed === false || $bits === false || $bits < 0 || $bits > strlen($packed) * 8) {
                throw ValidationException::withMessages(['allowed_ip_ranges' => __('Enter valid IPv4 or IPv6 addresses and CIDR ranges.')]);
            }
        }
        $domains = collect(preg_split('/[\s,]+/', Str::lower((string) ($data['allowed_email_domains'] ?? ''))) ?: [])->filter();
        if ($domains->contains(fn ($domain) => ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}\z/D', $domain))) {
            throw ValidationException::withMessages(['allowed_email_domains' => __('Enter valid email domains.')]);
        }
        if ($ranges->isNotEmpty() && ! $ranges->contains(fn (string $range): bool => $this->rangeContains($range, (string) $request->ip()))) {
            throw ValidationException::withMessages(['allowed_ip_ranges' => __('Include your current IP address so you do not lock yourself out.')]);
        }
        $sso = $organization->sso_configuration ?? [];
        if (filled($data['sso_issuer'] ?? null)) {
            $sso['issuer'] = rtrim($data['sso_issuer'], '/');
        }
        if (filled($data['sso_client_id'] ?? null)) {
            $sso['client_id'] = $data['sso_client_id'];
        }
        if (filled($data['sso_client_secret'] ?? null)) {
            $sso['client_secret'] = $data['sso_client_secret'];
        }
        $ssoChanged = ($sso['issuer'] ?? null) !== data_get($organization->sso_configuration, 'issuer')
            || ($sso['client_id'] ?? null) !== data_get($organization->sso_configuration, 'client_id')
            || filled($data['sso_client_secret'] ?? null)
            || (bool) $data['sso_enforced'] !== (bool) $organization->sso_enforced;
        if ($ssoChanged) {
            $entitlements->enforce($organization, 'sso');
        }
        if ($data['sso_enforced'] && (! filled($sso['issuer'] ?? null) || ! filled($sso['client_id'] ?? null) || ! filled($sso['client_secret'] ?? null))) {
            throw ValidationException::withMessages(['sso_enforced' => __('Configure the issuer, client ID, and client secret before enforcing SSO.')]);
        }
        $organization->update([
            'allowed_ip_ranges' => $ranges->all(), 'allowed_email_domains' => $domains->values()->all(),
            'require_two_factor' => (bool) $data['require_two_factor'], 'session_idle_minutes' => $data['session_idle_minutes'] ?? null,
            'sso_configuration' => $sso === [] ? null : $sso, 'sso_enforced' => (bool) $data['sso_enforced'],
        ]);

        return back()->with('success', __('Workspace security policy updated.'));
    }

    /**
     * Test whether an IP address belongs to a previously validated same-family address or CIDR range.
     */
    private function rangeContains(string $range, string $ip): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $address = @inet_pton($ip);
        $base = @inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $bits = $prefix === null ? strlen($address) * 8 : (int) $prefix;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        return $remainder === 0 || ((ord($address[$bytes]) & ((0xFF << (8 - $remainder)) & 0xFF)) === (ord($base[$bytes]) & ((0xFF << (8 - $remainder)) & 0xFF)));
    }

    /**
     * Require workspace management access and an existing non-owner member, remove them, and queue billing-seat synchronization.
     */
    public function removeMember(Request $request, User $member, RemoveOrganizationMemberAction $removeMember): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $this->authorize('manageMembers', $organization);
        try {
            $removeMember->handle($organization, $member);
        } catch (OrganizationMemberOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Member removed.'));
    }

    /**
     * Require current-workspace ownership and name/password/two-factor confirmation before deleting an empty, inactive workspace.
     *
     * @return RedirectResponse Workspace settings after the user's personal workspace is ensured.
     */
    public function destroy(
        Request $request,
        Organization $organization,
        TwoFactorAuthentication $twoFactor,
        PersonalOrganization $personal,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($organization->id === $user->current_organization_id && $organization->owner->is($user), 403);
        $rules = ['confirmation' => ['required', Rule::in([$organization->name])]];
        if ($user->hasLocalPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        if ($user->twoFactorEnabled()) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }
        $data = $request->validateWithBag('deleteWorkspace', $rules);
        if ($user->twoFactorEnabled() && ! $twoFactor->verifyUser($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')])->errorBag('deleteWorkspace');
        }

        abort_if($organization->members()->where('users.id', '!=', $user->id)->exists(), 422, 'Remove every teammate before deleting this workspace.');
        abort_if(
            Build::query()->whereIn('status', Build::ACTIVE_STATUSES)->whereHas('repository', fn ($query) => $query->where('organization_id', $organization->id))->exists()
                || ServerCommandExecution::query()->active()->whereHas('server', fn ($query) => $query->where('organization_id', $organization->id))->exists(),
            409,
            'Wait for active deployments and commands to finish before deleting this workspace.',
        );

        DB::transaction(function () use ($organization, $user): void {
            $organization->delete();
            $user->forceFill(['current_organization_id' => null])->save();
        });
        $personal->ensure($user->refresh());

        return redirect()->route('organizations.index')->with('success', __('Workspace deleted. A new empty personal workspace was created.'));
    }
}

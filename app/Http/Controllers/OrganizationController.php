<?php

namespace App\Http\Controllers;

use App\Jobs\SyncOrganizationSeatQuantityJob;
use App\Models\Build;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Entitlements;
use App\Services\PersonalOrganization;
use App\Services\PlanLimits;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
    public function invite(Request $request, PlanLimits $limits, Entitlements $entitlements): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization?->permits($request->user(), 'manage'), 403);
        $entitlements->enforce($organization, 'teams');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Organization::ROLES)],
        ]);
        $email = Str::lower($data['email']);
        $allowedDomains = $organization->allowed_email_domains ?? [];
        abort_if($allowedDomains !== [] && ! in_array(Str::afterLast($email, '@'), $allowedDomains, true), 422, 'This email domain is not allowed by the workspace security policy.');
        abort_if($organization->members()->whereRaw('LOWER(email) = ?', [$email])->exists(), 422, 'This person is already a member.');
        [$invitation, $token] = $limits->withinLimit($request->user(), 'members', function ($lockedOrganization) use ($request, $data, $email): array {
            $token = Str::random(64);
            $invitation = $lockedOrganization->invitations()->updateOrCreate(['email' => $email], [
                'invited_by' => $request->user()->id,
                'role' => $data['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ]);

            return [$invitation, $token];
        });
        $url = route('organizations.invitations.accept', ['invitation' => $invitation, 'token' => $token]);
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($organization->name, $url));

        return back()->with('success', __('Invitation sent.'));
    }

    /**
     * Validate the invitation token, email ownership, expiry, and seat allowance under locks before joining the workspace.
     *
     * @return RedirectResponse Workspace settings after switching membership and queuing billing-seat synchronization.
     */
    public function accept(Request $request, OrganizationInvitation $invitation, PlanLimits $limits): RedirectResponse
    {
        abort_unless($invitation->isUsable() && hash_equals($invitation->token_hash, hash('sha256', (string) $request->query('token'))), 403);
        abort_unless(Str::lower($request->user()->email) === Str::lower($invitation->email), 403);
        DB::transaction(function () use ($invitation, $limits, $request): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($invitation->organization_id);
            $lockedInvitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($lockedInvitation->isUsable()
                && hash_equals($lockedInvitation->token_hash, hash('sha256', (string) $request->query('token')))
                && Str::lower($request->user()->email) === Str::lower($lockedInvitation->email), 403);
            $limits->enforceForOrganization($organization, 'members');
            $organization->members()->syncWithoutDetaching([$request->user()->id => ['role' => $lockedInvitation->role]]);
            $lockedInvitation->update(['accepted_at' => now()]);
        }, 3);
        $request->user()->update(['current_organization_id' => $invitation->organization_id]);
        SyncOrganizationSeatQuantityJob::dispatch($invitation->organization_id);

        return redirect()->route('organizations.index')->with('success', __('Workspace invitation accepted.'));
    }

    /**
     * Require visibility of the bound workspace, select it as current, and redirect to its dashboard.
     */
    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($organization->permits($request->user(), 'view'), 403);
        $request->user()->update(['current_organization_id' => $organization->id]);

        return redirect()->route('dashboard')->with('success', __('Workspace changed to :name.', ['name' => $organization->name]));
    }

    /**
     * Validate a role for an existing non-owner member of the managed workspace, update the membership, and redirect back.
     */
    public function updateMember(Request $request, User $member): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization?->permits($request->user(), 'manage'), 403);
        abort_if((int) $organization->owner_id === (int) $member->id, 422, 'The owner role cannot be changed.');
        abort_unless($organization->members()->whereKey($member->id)->exists(), 404);
        $data = $request->validate(['role' => ['required', Rule::in(Organization::ROLES)]]);
        $organization->members()->updateExistingPivot($member->id, ['role' => $data['role']]);

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
    public function removeMember(Request $request, User $member): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization?->permits($request->user(), 'manage'), 403);
        abort_if((int) $organization->owner_id === (int) $member->id, 422, 'The workspace owner cannot be removed.');
        abort_unless($organization->members()->whereKey($member->id)->exists(), 404);
        $organization->members()->detach($member->id);
        SyncOrganizationSeatQuantityJob::dispatch($organization->id);

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

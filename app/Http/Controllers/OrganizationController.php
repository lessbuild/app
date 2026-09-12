<?php

namespace App\Http\Controllers;

use App\Actions\Organization\AcceptOrganizationInvitationAction;
use App\Actions\Organization\DeleteOrganizationAction;
use App\Actions\Organization\InviteOrganizationMemberAction;
use App\Actions\Organization\RemoveOrganizationMemberAction;
use App\Actions\Organization\SwitchOrganizationAction;
use App\Actions\Organization\UpdateOrganizationMemberAction;
use App\Actions\Organization\UpdateOrganizationNotificationPreferencesAction;
use App\Actions\Organization\UpdateOrganizationSecurityPolicyAction;
use App\Exceptions\OrganizationDeletionOperationException;
use App\Exceptions\OrganizationInvitationOperationException;
use App\Exceptions\OrganizationMemberOperationException;
use App\Http\Requests\AcceptOrganizationInvitationRequest;
use App\Http\Requests\DeleteOrganizationRequest;
use App\Http\Requests\StoreOrganizationInvitationRequest;
use App\Http\Requests\UpdateOrganizationMemberRequest;
use App\Http\Requests\UpdateOrganizationNotificationPreferencesRequest;
use App\Http\Requests\UpdateOrganizationSecurityPolicyRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\PersonalOrganization;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function updateNotificationPreferences(UpdateOrganizationNotificationPreferencesRequest $request, UpdateOrganizationNotificationPreferencesAction $updatePreferences): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $updatePreferences->handle($organization, $request->validated());

        return back()->with('success', __('Workspace notification preferences updated.'));
    }

    /**
     * Validate workspace network, email-domain, authentication, idle-timeout, and SSO policy settings before saving them.
     *
     * New IP restrictions must retain the current client address; changed SSO settings require the SSO entitlement.
     */
    public function updateSecurityPolicy(UpdateOrganizationSecurityPolicyRequest $request, UpdateOrganizationSecurityPolicyAction $updateSecurity): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $updateSecurity->handle($organization, $request->validated(), (string) $request->ip());

        return back()->with('success', __('Workspace security policy updated.'));
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
        DeleteOrganizationRequest $request,
        Organization $organization,
        DeleteOrganizationAction $delete,
    ): RedirectResponse {
        $user = $request->user();
        try {
            $delete->handle($user, $organization, $request->validated());
        } catch (OrganizationDeletionOperationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect()->route('organizations.index')->with('success', __('Workspace deleted. A new empty personal workspace was created.'));
    }
}

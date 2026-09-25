<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\Sites\CreateSiteForWorkspace;
use App\Modules\Analytics\Actions\Workspaces\EnsurePersonalWorkspace;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function create(
        EnsurePersonalWorkspace $ensureWorkspace,
        AnalyticsWorkspaceAccess $access,
        AnalyticsPlanAuthority $plans,
    ): View
    {
        $user = request()->user();
        $workspace = $ensureWorkspace->handle($user);
        abort_unless($access->roleFor($user, $workspace)?->canManageSites() === true, 403);
        $plan = $plans->resolve($workspace);
        $siteCount = $workspace->sites()->count();
        $siteLimitKnown = $plan->hasLimit('sites');
        $siteLimit = $plan->limit('sites');
        $canCreateSite = $plan->available
            && $plan->allows('site_management')
            && $siteLimitKnown
            && ($siteLimit === null || $siteCount < $siteLimit);

        return view('analytics::sites.create', compact(
            'workspace',
            'siteCount',
            'siteLimit',
            'siteLimitKnown',
            'canCreateSite',
            'plan',
        ));
    }

    public function store(
        Request $request,
        EnsurePersonalWorkspace $ensureWorkspace,
        AnalyticsWorkspaceAccess $access,
        AnalyticsPlanAuthority $plans,
        CreateSiteForWorkspace $createSite,
    ): RedirectResponse
    {
        $workspace = $ensureWorkspace->handle($request->user());
        abort_unless($access->roleFor($request->user(), $workspace)?->canManageSites() === true, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);

        $domain = strtolower(trim($validated['domain']));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim((string) strtok($domain, '/'));

        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain)) {
            return back()->withErrors(['domain' => 'Enter a domain such as example.com.'])->withInput();
        }

        $site = $createSite->handle($workspace, $plans->resolve($workspace), [
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(5)),
            'domains' => [$domain],
            'timezone' => $validated['timezone'],
        ]);

        return to_route('analytics.sites.setup', $site)->with('status', 'Your site is ready for verification.');
    }

    public function setup(Site $site): View
    {
        $this->authorize('manage', $site);

        return view('analytics::sites.setup', compact('site'));
    }

    public function verify(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('manage', $site);

        $validated = $request->validate(['token' => ['required', 'string']]);

        $manualVerificationAllowed = app()->environment(['local', 'testing']);
        $tokenMatches = hash_equals($site->verification_token, $validated['token']);

        if (! $tokenMatches || (! $manualVerificationAllowed && ! $site->hasVerificationRecord($validated['token']))) {
            return back()->withErrors(['token' => 'That verification token does not match.']);
        }

        $site->update(['verified_at' => now()]);

        return back()->with('status', 'Domain verified. Your tracker can now collect events.');
    }
}

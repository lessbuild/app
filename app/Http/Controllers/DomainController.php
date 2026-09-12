<?php

namespace App\Http\Controllers;

use App\Actions\Domain\DeleteWebsiteDomainAction;
use App\Actions\Domain\IssueTemporaryWebsiteDomainAction;
use App\Actions\Domain\SaveWebsiteDomainAction;
use App\Actions\Domain\SynchronizeWebsiteDomainAction;
use App\Exceptions\WebsiteDomainOperationException;
use App\Http\Requests\IssueTemporaryWebsiteDomainRequest;
use App\Http\Requests\StoreWebsiteDomainRequest;
use App\Models\Provider;
use App\Models\WebsiteDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainController extends Controller
{
    /**
     * Render workspace websites, domain records, DNS providers, and domain-management availability.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;

        return view('domains.index', [
            'websites' => $request->user()->workspaceWebsites()->with(['domains.dnsProvider', 'server'])->orderBy('name')->get(),
            'dnsProviders' => $request->user()->workspaceProviders()->whereIn('provider', Provider::DNS_TYPES)->orderBy('name')->get(),
            'temporaryBaseDomain' => config('domains.temporary_base_domain'),
            'canManage' => $organization->permits($request->user(), 'deploy'),
        ]);
    }

    /**
     * Normalize and validate a hostname, alias/redirect type, and optional workspace Cloudflare provider.
     *
     * @return RedirectResponse The saved domain result after DNS synchronization and queued proxy configuration.
     */
    public function store(StoreWebsiteDomainRequest $request, SaveWebsiteDomainAction $saveDomain): RedirectResponse
    {
        $website = $request->website();
        $this->authorize('update', $website);
        $result = $saveDomain->handle($website, $request->user(), $request->validated());

        return back()->with($result->warning ? 'warning' : 'success', $result->warning ?: __('Domain added. Caddy will request its TLS certificate automatically.'));
    }

    /**
     * Issue a unique hostname beneath the configured temporary domain for an editable workspace website.
     *
     * @return RedirectResponse A DNS outcome or a missing-base-domain validation error.
     */
    public function temporary(IssueTemporaryWebsiteDomainRequest $request, IssueTemporaryWebsiteDomainAction $issueTemporary): RedirectResponse
    {
        $website = $request->website();
        $this->authorize('update', $website);
        try {
            $result = $issueTemporary->handle($website, $request->user(), $request->validated()['dns_provider_id']);
        } catch (WebsiteDomainOperationException $exception) {
            return back()->withErrors(['domain' => __($exception->getMessage())]);
        }

        return back()->with($result->warning ? 'warning' : 'success', $result->warning ?: __('Temporary domain issued.'));
    }

    /**
     * Authorize the domain's website and redirect with the Cloudflare synchronization result or missing-provider error.
     */
    public function sync(WebsiteDomain $domain, SynchronizeWebsiteDomainAction $synchronizeDomain): RedirectResponse
    {
        $this->authorize('update', $domain->website);
        if (! $domain->dnsProvider) {
            return back()->withErrors(['domain' => __('Attach a Cloudflare provider before syncing DNS.')]);
        }
        $warning = $synchronizeDomain->handle($domain);

        return back()->with($warning ? 'warning' : 'success', $warning ?: __('DNS record synchronized.'));
    }

    /**
     * Authorize and remove a non-primary domain after DNS deletion succeeds, then queue proxy configuration.
     *
     * @return RedirectResponse The deletion result; a DNS failure preserves the domain record.
     */
    public function destroy(WebsiteDomain $domain, DeleteWebsiteDomainAction $deleteDomain): RedirectResponse
    {
        $this->authorize('update', $domain->website);
        try {
            if (! $deleteDomain->handle($domain)) {
                return back()->withErrors(['domain' => __('Cloudflare could not remove the DNS record. Nothing was deleted.')]);
            }
        } catch (WebsiteDomainOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Domain removed.'));
    }
}

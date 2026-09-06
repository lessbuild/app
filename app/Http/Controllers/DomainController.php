<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyWebsiteDomainsJob;
use App\Models\Provider;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Rules\Hostname;
use App\Services\CloudflareDns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class DomainController extends Controller
{
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

    public function store(Request $request, CloudflareDns $cloudflare): RedirectResponse
    {
        $website = $this->website($request);
        $this->authorize('update', $website);
        $request->merge(['hostname' => strtolower(rtrim(trim((string) $request->input('hostname')), '.'))]);
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253', new Hostname, Rule::unique('website_domains', 'hostname')],
            'type' => ['required', Rule::in(['alias', 'redirect'])],
            'redirect_url' => ['nullable', 'required_if:type,redirect', 'url:https', 'max:500'],
            'dns_provider_id' => ['nullable', Rule::exists('providers', 'id')->where(fn ($query) => $query
                ->where('organization_id', $website->organization_id)
                ->where('provider', Provider::TYPE_CLOUDFLARE))],
        ]);
        $domain = $website->domains()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'redirect_url' => $data['type'] === 'redirect' ? $data['redirect_url'] : null,
        ]);
        $warning = $this->syncDns($domain, $cloudflare);
        ApplyWebsiteDomainsJob::dispatch($website->id);

        return back()->with($warning ? 'warning' : 'success', $warning ?: __('Domain added. Caddy will request its TLS certificate automatically.'));
    }

    public function temporary(Request $request, CloudflareDns $cloudflare): RedirectResponse
    {
        $website = $this->website($request);
        $this->authorize('update', $website);
        $base = strtolower(trim((string) config('domains.temporary_base_domain')));
        if ($base === '') {
            return back()->withErrors(['domain' => __('Set TEMPORARY_APP_DOMAIN before issuing temporary domains.')]);
        }
        $data = $request->validate([
            'dns_provider_id' => ['required', Rule::exists('providers', 'id')->where(fn ($query) => $query
                ->where('organization_id', $website->organization_id)
                ->where('provider', Provider::TYPE_CLOUDFLARE))],
        ]);
        $prefix = Str::limit($website->deployment_slug, 40, '').'-'.Str::lower(Str::random(8));
        $domain = $website->domains()->create([
            'created_by' => $request->user()->id,
            'dns_provider_id' => $data['dns_provider_id'],
            'hostname' => $prefix.'.'.$base,
            'type' => 'alias',
            'is_temporary' => true,
        ]);
        $warning = $this->syncDns($domain, $cloudflare);
        ApplyWebsiteDomainsJob::dispatch($website->id);

        return back()->with($warning ? 'warning' : 'success', $warning ?: __('Temporary domain issued.'));
    }

    public function sync(WebsiteDomain $domain, CloudflareDns $cloudflare): RedirectResponse
    {
        $this->authorize('update', $domain->website);
        if (! $domain->dnsProvider) {
            return back()->withErrors(['domain' => __('Attach a Cloudflare provider before syncing DNS.')]);
        }
        $warning = $this->syncDns($domain, $cloudflare);

        return back()->with($warning ? 'warning' : 'success', $warning ?: __('DNS record synchronized.'));
    }

    public function destroy(WebsiteDomain $domain, CloudflareDns $cloudflare): RedirectResponse
    {
        $this->authorize('update', $domain->website);
        abort_if($domain->type === 'primary', 422, 'The primary domain must be changed from website settings.');
        try {
            $cloudflare->delete($domain);
        } catch (Throwable) {
            return back()->withErrors(['domain' => __('Cloudflare could not remove the DNS record. Nothing was deleted.')]);
        }
        $websiteId = $domain->website_id;
        $domain->delete();
        ApplyWebsiteDomainsJob::dispatch($websiteId);

        return back()->with('success', __('Domain removed.'));
    }

    private function website(Request $request): Website
    {
        return $request->user()->workspaceWebsites()->findOrFail((int) $request->input('website_id'));
    }

    private function syncDns(WebsiteDomain $domain, CloudflareDns $cloudflare): ?string
    {
        if (! $domain->dns_provider_id) {
            return __('Domain saved. Point its DNS record to the attached server, then run the certificate check.');
        }
        try {
            $cloudflare->sync($domain);

            return null;
        } catch (Throwable) {
            $domain->forceFill(['dns_status' => 'error', 'last_error' => 'DNS synchronization failed.', 'last_checked_at' => now()])->save();

            return __('Domain saved, but Cloudflare synchronization failed. Verify token permissions and zone access.');
        }
    }
}

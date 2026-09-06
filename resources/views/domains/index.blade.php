<x-layouts.app>
    <x-layouts.partials.heading icon="link" :title="__('Domains & TLS')" :description="__('Manage aliases, redirects, Cloudflare DNS, temporary domains, and certificate health.')" />

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-5">
            @forelse($websites as $website)
                <section class="overflow-hidden rounded-2xl border border-primary bg-primary">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-primary p-5"><div><h2 class="font-black text-primary">{{ $website->name }}</h2><a href="https://{{ $website->url }}" target="_blank" rel="noopener noreferrer" class="text-sm text-ternary">{{ $website->url }}</a></div><span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold text-secondary">{{ $website->domains->count() }} {{ __('domains') }}</span></header>
                    <div class="divide-y divide-primary">
                        @foreach($website->domains->sortBy(fn($domain) => $domain->type === 'primary' ? 0 : 1) as $domain)
                            <article class="flex flex-wrap items-center gap-3 p-4"><div class="min-w-0 flex-1"><p class="truncate font-bold text-primary">{{ $domain->hostname }}</p><p class="mt-1 text-xs text-secondary">{{ ucfirst($domain->type) }}@if($domain->is_temporary) · {{ __('Temporary') }}@endif · DNS {{ $domain->dns_status }} · TLS {{ $domain->ssl_status }}@if($domain->certificate_expires_at) · {{ __('expires :date', ['date'=>$domain->certificate_expires_at->toDateString()]) }}@endif</p>@if($domain->redirect_url)<p class="mt-1 truncate text-xs text-secondary">→ {{ $domain->redirect_url }}</p>@endif</div>@if($domain->dnsProvider)<form method="POST" action="{{ route('domains.sync', $domain) }}">@csrf<button type="submit" class="button secondary">{{ __('Sync DNS') }}</button></form>@endif @if($domain->type !== 'primary')<form method="POST" action="{{ route('domains.destroy', $domain) }}">@csrf @method('DELETE')<button type="submit" class="button tertiary">{{ __('Remove') }}</button></form>@endif</article>
                        @endforeach
                    </div>
                </section>
            @empty
                <x-lists.empty title="No websites" description="Create a website before attaching domains." />
            @endforelse
        </div>

        @if($canManage)
            <aside class="space-y-5">
                <form method="POST" action="{{ route('domains.store') }}" class="rounded-2xl border border-primary bg-primary p-5">@csrf<h2 class="font-black text-primary">{{ __('Add domain') }}</h2><div class="mt-4 space-y-3"><label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Website') }}</span><select name="website_id" class="input secondary rounded" required>@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select></label><label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Hostname') }}</span><input name="hostname" placeholder="www.example.com" class="input secondary rounded" required></label><label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Behavior') }}</span><select name="type" class="input secondary rounded"><option value="alias">{{ __('Serve application') }}</option><option value="redirect">{{ __('Redirect') }}</option></select></label><label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Redirect destination') }}</span><input type="url" name="redirect_url" placeholder="https://example.com" class="input secondary rounded"></label><label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('DNS automation') }}</span><select name="dns_provider_id" class="input secondary rounded"><option value="">{{ __('Manual DNS') }}</option>@foreach($dnsProviders as $provider)<option value="{{ $provider->id }}">{{ $provider->name }}</option>@endforeach</select></label><button type="submit" class="button primary w-full">{{ __('Add domain') }}</button></div></form>
                <form method="POST" action="{{ route('domains.temporary') }}" class="rounded-2xl border border-primary bg-primary p-5">@csrf<h2 class="font-black text-primary">{{ __('Temporary domain') }}</h2><p class="mt-1 text-sm text-secondary">{{ $temporaryBaseDomain ? __('Issue a shareable :domain address.', ['domain'=>'*.'.$temporaryBaseDomain]) : __('Set TEMPORARY_APP_DOMAIN to enable this feature.') }}</p><div class="mt-4 space-y-3"><select name="website_id" class="input secondary rounded" required>@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select><select name="dns_provider_id" class="input secondary rounded" required><option value="">{{ __('Select Cloudflare provider') }}</option>@foreach($dnsProviders as $provider)<option value="{{ $provider->id }}">{{ $provider->name }}</option>@endforeach</select><button type="submit" class="button primary w-full" @disabled(!$temporaryBaseDomain)>{{ __('Issue domain') }}</button></div></form>
            </aside>
        @endif
    </div>
</x-layouts.app>

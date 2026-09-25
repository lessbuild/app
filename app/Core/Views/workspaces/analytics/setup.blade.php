<x-signal.layouts.platform
    :title="__('Analytics site setup')"
    :description="__('Verify the registered domain and install its Analytics tracker.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="$setup->name" :description="__('Verify the domain, then install the tracker on your website.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.analytics.sites.index', $workspace)" variant="secondary">{{ __('All sites') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status'))<x-signal.ui.alert class="mt-5" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>@endif
    @if ($errors->any())<x-signal.ui.alert class="mt-5" tone="danger" role="alert">{{ $errors->first() }}</x-signal.ui.alert>@endif

    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <x-signal.ui.card class="p-5">
            <p class="ui-eyebrow">{{ __('Domain verification') }}</p>
            @if ($setup->verified)
                <x-signal.ui.alert class="mt-3" tone="success" role="status">{{ __('This domain is verified.') }}</x-signal.ui.alert>
            @else
                <p class="mt-3 text-sm leading-6 text-muted">{{ __('Publish a DNS TXT record, then ask Analytics to check the real DNS record. Local development accepts the matching token directly.') }}</p>
                <dl class="mt-4 grid gap-3 text-sm"><div><dt class="font-semibold">{{ __('Record name') }}</dt><dd class="mt-1 break-all font-mono">{{ $setup->verificationRecordName }}</dd></div><div><dt class="font-semibold">{{ __('Record value') }}</dt><dd class="mt-1 break-all font-mono">{{ $setup->verificationToken }}</dd></div></dl>
                <form class="mt-5 space-y-3" method="POST" action="{{ route('core.workspace.analytics.sites.verify', [$workspace, $setup->id]) }}">
                    @csrf
                    <x-signal.ui.input-field name="token" :label="__('Verification token')" required autocomplete="off" />
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Check DNS verification') }}</x-signal.ui.button>
                </form>
            @endif
        </x-signal.ui.card>
        <x-signal.ui.card class="p-5">
            <p class="ui-eyebrow">{{ __('Tracker installation') }}</p>
            <p class="mt-3 text-sm leading-6 text-muted">{{ __('Install this snippet in the shared page layout on :domain.', ['domain' => $setup->domain]) }}</p>
            @if ($setup->trackerSnippet)
                <x-signal.ui.code-block :code="$setup->trackerSnippet" class="mt-4" />
            @else
                <x-signal.ui.alert class="mt-4" tone="warning" role="status">{{ __('The Analytics tracker address is not configured for this environment. Contact your workspace administrator before installing a snippet.') }}</x-signal.ui.alert>
            @endif
            <p class="mt-4 text-sm text-muted">{{ $setup->hasEvents ? __('Analytics has received an event for this site.') : __('Waiting for the first accepted tracker event.') }}</p>
        </x-signal.ui.card>
    </div>
</x-signal.layouts.platform>

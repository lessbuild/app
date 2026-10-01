<x-signal.layouts.account :account="$account" :title="__('Clients')" :description="__('Tools for agencies: your own branding on what clients see, a monthly report for each client, and costs by client with your markup.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-slot:actions>
        <x-signal.ui.button :href="route('account.clients.costs')" variant="secondary">{{ __('Download costs by client') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('account.clients', ['dialog' => 'add-client'])" variant="primary" data-modal-trigger="add-client">{{ __('Add a client') }}</x-signal.ui.button>
    </x-slot:actions>
    <x-signal.overlays.form-modal id="add-client" :title="__('Add a client')" :action="route('account.clients.store')" :submit="__('Add client')">
        @include('account._client-fields', ['prefix' => 'new-client', 'client' => null])
    </x-signal.overlays.form-modal>

    <x-signal.ui.settings-section id="branding" :title="__('White label')" :description="__('Show your name, logo and colour instead of ours on status pages, shared Analytics reports and client reports, without “Powered by”. Leave the name empty to turn it off.')">
        <form method="POST" action="{{ route('account.clients.branding') }}" class="grid items-end gap-4 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            @method('PUT')
            @unless ($whiteLabel)
                <div class="sm:col-span-2"><x-signal.ui.alert tone="info">{{ __('White labelling comes with the Deploy Team plan and above. You can set it up now; it shows once your plan includes it.') }} <a href="{{ route('account.billing', ['tab' => 'deploy']) }}" class="font-bold underline">{{ __('See plans') }}</a></x-signal.ui.alert></div>
            @endunless
            <x-signal.ui.input-field name="brand_name" :label="__('Name')" :value="$account->brand_name" maxlength="100" placeholder="Acme Studio" />
            <x-signal.ui.input-field name="brand_color" :label="__('Colour')" :value="$account->brand_color" maxlength="7" placeholder="#1f6feb" />
            <div class="sm:col-span-2"><x-signal.ui.input-field name="brand_logo_url" type="url" :label="__('Logo address')" :value="$account->brand_logo_url" maxlength="500" placeholder="https://acme.example/logo.svg" :description="__('An HTTPS image, about 40 pixels tall.')" /></div>
            <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Save branding') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    @forelse ($clients as $client)
        <x-signal.ui.settings-section :id="'client-'.$client->id" :title="$client->name" :description="collect([
            trans_choice(':count project|:count projects', count($client->project_ids)),
            $client->markup_percent > 0 ? __(':percent% markup', ['percent' => $client->markup_percent]) : null,
            $client->monthly_report && $client->emails !== [] ? __('monthly report to :emails', ['emails' => implode(', ', $client->emails)]) : __('no monthly report'),
        ])->filter()->implode(' · ')">
            <div class="grid gap-4 p-4 sm:p-6">
                <div class="flex flex-wrap gap-3">
                    <x-signal.ui.button :href="route('account.clients.report', $client->id)" variant="secondary" size="sm">{{ __('Preview last month’s report') }}</x-signal.ui.button>
                </div>
                <x-signal.ui.disclosure :title="__('Edit')">
                    <form method="POST" action="{{ route('account.clients.update', $client->id) }}" class="grid gap-4">
                        @csrf
                        @method('PUT')
                        @include('account._client-fields', ['prefix' => 'client-'.$client->id, 'client' => $client])
                        <div><x-signal.ui.button type="submit" variant="primary">{{ __('Save') }}</x-signal.ui.button></div>
                    </form>
                    <form method="POST" action="{{ route('account.clients.destroy', $client->id) }}" class="mt-4">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove client') }}</x-signal.ui.button></form>
                </x-signal.ui.disclosure>
            </div>
        </x-signal.ui.settings-section>
    @empty
        <x-signal.ui.empty-state icon="users" :title="__('No clients yet')" :description="__('Add a client to group the projects you run for them, send them a monthly report and pass costs on with your markup. To let a client see their projects in the app, invite them as a viewer limited to those projects on the Members page.')" />
    @endforelse
</x-signal.layouts.account>

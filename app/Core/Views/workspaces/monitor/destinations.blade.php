<x-signal.layouts.platform :title="__('Monitor alert destinations')" :description="__('Manage alert routes and review delivery history.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Alert destinations and delivery history')" :description="__('Destination secrets stay in Monitor and are shown only once when newly issued.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.monitor.alerts', $workspace)" variant="secondary">{{ __('Alert rules') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.settings', $workspace)" variant="secondary">{{ __('Monitor settings') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="primary">{{ __('Workspace management') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status')) <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert> @endif
    @if ($errors->any()) <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert> @endif

    <x-signal.ui.card class="mt-6 p-4">
        <form method="GET" action="{{ route('core.workspace.monitor.destinations', $workspace) }}" class="grid gap-3 sm:grid-cols-3">
            <x-signal.ui.input-field id="destinations-search" name="destination_search" :label="__('Find destinations')" :value="request('destination_search')" maxlength="100" />
            <x-signal.ui.input-field id="destinations-recipient-search" name="recipient_search" :label="__('Find verified recipients')" :value="request('recipient_search')" maxlength="100" />
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Search destinations and recipients') }}</x-signal.ui.button>
        </form>
        @if ($settings['recipients']->hasPages())<nav class="mt-3" aria-label="{{ __('Recipient choices') }}">{{ $settings['recipients']->links() }}</nav>@endif
    </x-signal.ui.card>

    @if ($settings['can_create'])
        <x-signal.ui.card class="mt-6 p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Create destination') }}</h2>
            <form method="POST" action="{{ route('core.workspace.monitor.destinations.store', $workspace) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                <x-signal.ui.input-field id="new-destination-name" name="name" :label="__('Destination name')" :value="old('name')" required maxlength="120" />
                <x-signal.ui.select-field id="new-destination-type" name="type" :label="__('Channel')" required>@foreach ($settings['types'] as $type)<option value="{{ $type['value'] }}" @selected(old('type') === $type['value'])>{{ $type['label'] }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.select-field id="new-destination-recipient" name="recipient_reference" :label="__('Verified email recipient')"><option value="">{{ __('Choose for email destinations') }}</option>@foreach ($settings['recipients'] as $recipient)<option value="{{ $recipient['reference'] }}">{{ $recipient['name'] }}</option>@endforeach</x-signal.ui.select-field>
                <x-signal.ui.input-field id="new-destination-endpoint" name="endpoint_url" :label="__('HTTPS endpoint')" value="" placeholder="https://…" maxlength="2048" :restore="false" />
                <x-signal.ui.input-field id="new-destination-secret" name="signing_secret" :label="__('Provider routing key')" type="password" value="" autocomplete="new-password" maxlength="256" :restore="false" />
                <p class="text-xs leading-5 text-muted sm:col-span-2">{{ __('Endpoint and routing-key fields are never retained in form session data. Leave them empty when editing to keep the current target. New webhook signing keys appear once after creation.') }}</p>
                <x-signal.ui.checkbox id="new-destination-enabled" name="enabled" :checked="true" :restore="false" unchecked-value="0">{{ __('Enabled') }}</x-signal.ui.checkbox>
                <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Create destination') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    @endif

    <div class="mt-6 grid gap-4">
        @forelse ($items as $item)
            @php($destinationIndex = $loop->index)
            <x-signal.ui.card class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="ui-eyebrow">{{ $item['type_label'] }} · {{ $item['target'] }} · {{ $item['archived'] ? __('Archived') : ($item['enabled'] ? __('Enabled') : __('Disabled')) }}</p><h2 class="mt-1 text-lg font-extrabold text-ink">{{ $item['name'] }}</h2></div>
                    <span class="text-xs text-muted">{{ __('Version :version · :rules rules', ['version' => $item['version'], 'rules' => $item['rule_count']]) }}</span>
                </div>
                @if ($item['can_update'] && ! $item['archived'])
                    <x-signal.ui.disclosure :title="__('Edit destination and delivery history')" class="mt-4">
                        <form method="POST" action="{{ route('core.workspace.monitor.destinations.update', $workspace) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                            @csrf @method('PATCH')
                            <x-signal.ui.input type="hidden" name="destination_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" />
                            <x-signal.ui.input-field :id="'destination-'.$destinationIndex.'-name'" name="name" :label="__('Destination name')" :value="$item['name']" required maxlength="120" :restore="false" />
                            <x-signal.ui.select-field :id="'destination-'.$destinationIndex.'-type'" name="type" :label="__('Channel')" required>@foreach ($settings['types'] as $type)<option value="{{ $type['value'] }}" @selected($item['type'] === $type['value'])>{{ $type['label'] }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.select-field :id="'destination-'.$destinationIndex.'-recipient'" name="recipient_reference" :label="__('Verified email recipient')"><option value="">{{ __('Not an email destination') }}</option>@foreach ($settings['recipients'] as $recipient)<option value="{{ $recipient['reference'] }}" @selected($item['recipient_reference'] === $recipient['reference'])>{{ $recipient['name'] }}</option>@endforeach</x-signal.ui.select-field>
                            <x-signal.ui.input-field :id="'destination-'.$destinationIndex.'-endpoint'" name="endpoint_url" :label="__('Replace HTTPS endpoint (optional)')" value="" placeholder="{{ __('Leave blank to keep the current destination') }}" maxlength="2048" :restore="false" />
                            <x-signal.ui.input-field :id="'destination-'.$destinationIndex.'-secret'" name="signing_secret" :label="__('Replace provider routing key (optional)')" type="password" value="" autocomplete="new-password" maxlength="256" :restore="false" />
                            <x-signal.ui.checkbox :id="'destination-'.$destinationIndex.'-enabled'" name="enabled" :checked="$item['enabled']" :restore="false" unchecked-value="0">{{ __('Enabled') }}</x-signal.ui.checkbox>
                            <p class="text-xs text-muted sm:col-span-2">{{ __('Secret values are not loaded into Core and are not restored after validation errors.') }}</p>
                            <x-signal.ui.button type="submit" variant="primary" class="sm:col-span-2">{{ __('Save destination') }}</x-signal.ui.button>
                        </form>
                        @if ($item['type'] === 'webhook')
                            <form method="POST" action="{{ route('core.workspace.monitor.destinations.action', ['workspace' => $workspace, 'action' => 'rotate']) }}" class="mt-3">@csrf<x-signal.ui.input type="hidden" name="destination_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" /><x-signal.ui.button type="submit" variant="secondary">{{ __('Rotate signing key') }}</x-signal.ui.button></form>
                        @endif
                        <form method="POST" action="{{ route('core.workspace.monitor.destinations.action', ['workspace' => $workspace, 'action' => 'test']) }}" class="mt-3">@csrf<x-signal.ui.input type="hidden" name="destination_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" /><x-signal.ui.button type="submit" variant="secondary">{{ __('Queue test notification') }}</x-signal.ui.button></form>
                        <form method="POST" action="{{ route('core.workspace.monitor.destinations.archive', ['workspace' => $workspace, 'action' => 'archive']) }}" class="mt-3">@csrf @method('DELETE')<x-signal.ui.input type="hidden" name="destination_reference" :value="$item['reference']" /><x-signal.ui.input type="hidden" name="version" :value="$item['version']" /><x-signal.ui.button type="submit" variant="danger">{{ __('Archive destination') }}</x-signal.ui.button></form>
                    </x-signal.ui.disclosure>
                @endif
                <div class="mt-4 border-t border-line pt-4"><x-signal.ui.button :href="route('core.workspace.monitor.destinations', array_merge(request()->query(), ['workspace' => $workspace, 'delivery_destination' => $item['delivery_destination_reference'], 'delivery_page' => 1]))" variant="secondary">{{ __('Review delivery history') }}</x-signal.ui.button></div>
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state :title="__('No alert destinations')" :description="__('Add a verified email or supported HTTPS provider, then route alert rules to it.')" icon="bell" />
        @endforelse
    </div>
    @if ($items->hasPages())<nav class="mt-5" aria-label="{{ __('Destination pages') }}">{{ $items->links() }}</nav>@endif

    @if ($settings['delivery_destination'] !== null && $settings['deliveries'] !== null)
        <x-signal.ui.card class="mt-6 p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Delivery history for :name', ['name' => $settings['delivery_destination']['name']]) }}</h2>
            <form method="GET" action="{{ route('core.workspace.monitor.destinations', $workspace) }}" class="mt-3 flex flex-wrap items-end gap-3">
                <x-signal.ui.input type="hidden" name="delivery_destination" :value="$settings['delivery_destination']['reference']" />
                <x-signal.ui.input-field id="delivery-history-search" name="delivery_search" :label="__('Find deliveries')" :value="request('delivery_search')" maxlength="100" />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Search history') }}</x-signal.ui.button>
            </form>
            <div class="mt-4 grid gap-3">
                @forelse ($settings['deliveries'] as $delivery)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3 text-sm">
                        <div><p>{{ $delivery['event'] }} · {{ $delivery['status_label'] }} · {{ __(':count attempts', ['count' => $delivery['attempt_count']]) }} · {{ $delivery['created_at']?->diffForHumans() }} @if($delivery['last_error'])<span class="text-muted">({{ $delivery['last_error'] }})</span>@endif</p>@if($delivery['attempts'] !== [])<x-signal.ui.disclosure :title="__('Attempt details')" class="mt-1"><ul class="text-xs text-muted">@foreach($delivery['attempts'] as $attempt)<li>{{ __('Attempt :number · :status', ['number' => $attempt['number'], 'status' => $attempt['status']]) }}{{ $attempt['http_status'] ? ' · HTTP '.$attempt['http_status'] : '' }}{{ $attempt['error_code'] ? ' · '.$attempt['error_code'] : '' }}</li>@endforeach</ul></x-signal.ui.disclosure>@endif</div>
                        @if ($delivery['retryable'] && $settings['delivery_destination']['can_update'])
                            <form method="POST" action="{{ route('core.workspace.monitor.deliveries.retry', $workspace) }}">@csrf<x-signal.ui.input type="hidden" name="delivery_reference" :value="$delivery['reference']" /><x-signal.ui.input type="hidden" name="generation" :value="$delivery['generation']" /><x-signal.ui.checkbox :id="'selected-delivery-'.$loop->index.'-confirm'" name="confirm" value="1" :restore="false" required>{{ __('Reviewed; retry may duplicate') }}</x-signal.ui.checkbox><x-signal.ui.button type="submit" variant="secondary" class="ui-btn-sm">{{ __('Retry') }}</x-signal.ui.button></form>
                        @endif
                    </div>
                @empty
                    <x-signal.ui.empty-state :title="__('No matching delivery history')" :description="__('Try a different event, status, or error search.')" icon="bell" />
                @endforelse
            </div>
            @if ($settings['deliveries']->hasPages())<nav class="mt-5" aria-label="{{ __('Delivery history pages') }}">{{ $settings['deliveries']->links() }}</nav>@endif
        </x-signal.ui.card>
    @endif
</x-signal.layouts.platform>

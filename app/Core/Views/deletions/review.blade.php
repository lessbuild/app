<x-signal.layouts.account :user="$user" :title="$workspace ? __('Delete workspace') : __('Delete account')"
    :description="$workspace ? __('Review what will be removed from :name and its connected apps.', ['name' => $workspace->name]) : __('Review your shared account and all workspaces you own before deleting them.')">
    @if ($errors->any())
        <x-signal.ui.alert tone="warning" role="alert">
            <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-signal.ui.alert>
    @endif

    <x-signal.ui.panel as="section" class="space-y-4 p-6">
        <h2 class="text-lg font-extrabold text-ink">{{ __('What will be removed') }}</h2>
        <p class="text-sm leading-6 text-muted">{{ $workspace ? __('This workspace, its projects, connected app resources, settings, and exports will be removed. Your shared account stays active.') : __('Your shared sign-in, workspaces you own, projects, connected app resources, settings, and exports will be removed.') }}</p>
        <p class="text-sm leading-6 text-muted">{{ __('Access stops when you confirm. Each app then stops new work and waits for running operations before deleting its data. Once accepted, the request cannot be canceled.') }}</p>
        <ul class="list-inside list-disc text-sm text-ink">@foreach ($plan['workspaces'] as $owned)<li>{{ $owned->name }}</li>@endforeach</ul>
        <div class="grid gap-3 md:grid-cols-3">
            @foreach ($plan['details'] as $detail)
                <x-signal.ui.card class="p-4">
                    <h3 class="font-bold text-ink">{{ ucfirst($detail['product']) }} · {{ $detail['kind'] === 'account' ? __('Account') : __('Workspace') }}</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        @foreach ($detail['counts'] as $label => $count)
                            @if (is_numeric($count))<div class="flex justify-between gap-3"><dt class="text-muted">{{ str_replace('_', ' ', ucfirst($label)) }}</dt><dd class="font-mono font-bold text-ink">{{ number_format((int) $count) }}</dd></div>@endif
                        @endforeach
                    </dl>
                </x-signal.ui.card>
            @endforeach
        </div>
        <x-signal.ui.button :href="route('platform.account.export')" variant="secondary">{{ __('Download account export') }}</x-signal.ui.button>
        <p class="text-sm text-muted">{{ __('Download any product exports you need from each app before continuing.') }}</p>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="section" class="space-y-3 p-6">
        <h2 class="text-lg font-extrabold text-ink">{{ __('What remains') }}</h2>
        <ul class="list-inside list-disc space-y-2 text-sm leading-6 text-muted">@foreach ($plan['retained'] as $retained)<li>{{ $retained }}</li>@endforeach</ul>
        <p class="text-sm leading-6 text-muted">{{ __('Deleting Buildpusher data does not destroy your remote servers or cancel services purchased from external providers. Previously created system backups follow the operator’s backup retention schedule.') }}</p>
    </x-signal.ui.panel>

    @if ($plan['blockers'] !== [])
        <x-signal.ui.alert tone="warning" role="status">
            <h2 class="font-bold">{{ __('Resolve these items first') }}</h2>
            <ul class="mt-2 list-inside list-disc space-y-2">@foreach (array_unique(array_map([\App\Core\Services\Deletion\DeletionMessages::class, 'reason'], $plan['blockers'])) as $reason)<li>{{ $reason }}</li>@endforeach</ul>
        </x-signal.ui.alert>
    @else
        <x-signal.blocks.deletion-receipt :receipt-key="$receiptKey" :receipt-token="$receiptToken" />
        <x-signal.ui.panel as="section" class="space-y-4 p-6">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Confirm permanent deletion') }}</h2>
            <form method="POST" action="{{ $workspace ? route('platform.deletions.workspace.store', $workspace) : route('platform.deletions.account.store') }}" class="max-w-xl space-y-4">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $receiptKey }}">
                <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
                @if ($user->hasPassword())<x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" :restore="false" required autocomplete="current-password" />@endif
                @if ($user->twoFactorEnabled())<x-signal.ui.input-field name="code" :label="__('Authenticator or recovery code')" :restore="false" required autocomplete="one-time-code" />@endif
                <x-signal.ui.input-field name="confirmation" :label="$workspace ? __('Type the workspace name: :name', ['name' => $workspace->name]) : __('Type your email: :email', ['email' => $user->email])" :restore="false" required autocomplete="off" />
                <x-signal.ui.checkbox name="understood" required :restore="false">{{ __('I saved my recovery receipt and understand this permanently deletes the data listed above.') }}</x-signal.ui.checkbox>
                <div class="flex flex-wrap gap-3">
                    <x-signal.ui.button type="submit" variant="danger">{{ $workspace ? __('Delete workspace') : __('Delete my account') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="route('platform.account.security')" variant="secondary">{{ __('Keep my data') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.panel>
    @endif
</x-signal.layouts.account>
